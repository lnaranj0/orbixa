<?php

namespace Modules\Work\Tests\Security;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\IdentityTenancy\Application\AcceptInvitation;
use Modules\IdentityTenancy\Application\CreateOrganization;
use Modules\IdentityTenancy\Application\InviteMember;
use Modules\IdentityTenancy\Infrastructure\Persistence\User;
use Modules\Platform\Contracts\Tenancy\OrganizationId;
use Modules\Platform\Contracts\Tenancy\TenantContext;
use Modules\Platform\Tests\Security\SecurityTestCase;
use Modules\Portfolio\Application\AddProjectMember;
use Modules\Portfolio\Application\CreateProject;
use Modules\Portfolio\Application\UpdateProject;
use Modules\Teams\Application\AssignTeamMember;
use Modules\Teams\Application\CreateTeam;
use Modules\Work\Application\ChangeItemLabels;
use Modules\Work\Application\CreateLabel;
use Modules\Work\Application\CreateWorkItem;
use Modules\Work\Application\TransitionWorkItem;
use Modules\Work\Application\UpdateWorkItem;
use Modules\Work\Contracts\WorkItemNotFound;
use Modules\Work\Contracts\WorkOperationDenied;
use Modules\Work\Contracts\WorkRelationNotFound;
use Modules\Work\Contracts\WorkRuleViolation;
use Modules\Work\Contracts\WorkVersionConflict;

/**
 * 03 increment 4: work item use cases on real PostgreSQL as the runtime role
 * (spec "Crear elementos jerárquicos", "Prevención de ciclos",
 * "Priorización y clasificación", "Concurrencia", "Finalizar una tarea").
 */
final class WorkItemsTest extends SecurityTestCase
{
    private TenantContext $tenant;

    private User $owner;

    private OrganizationId $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->app->make(TenantContext::class);
        $this->owner = $this->user();
        $this->organization = $this->app->make(CreateOrganization::class)->handle($this->owner, 'Work Inc');
    }

    public function test_a_task_goes_from_backlog_to_done_without_integrations(): void
    {
        $this->as($this->owner, function () {
            $project = $this->project('ORBX');
            $first = $this->create($this->owner, $project, ['type' => 'task', 'title' => 'First']);
            $task = $this->create($this->owner, $project, ['type' => 'task', 'title' => 'Ship it']);
            $this->assertSame(['ORBX-1', 'ORBX-2'], [$this->key($first), $this->key($task)]);

            $transition = $this->app->make(TransitionWorkItem::class);
            $this->assertRule(WorkRuleViolation::TRANSITION, fn () => $transition->handle($this->owner, $task, 0, 'done'));

            $version = 0;
            foreach (['ready', 'active', 'review', 'done'] as $status) {
                $version = $transition->handle($this->owner, $task, $version, $status);
            }
            $row = DB::selectOne('select status, completed_at from work.items where id = ?', [$task]);
            $this->assertSame('done', $row->status);
            $this->assertNotNull($row->completed_at);
            $this->assertSame(1, $this->events('work.item.completed', $task));

            $this->assertRule(WorkRuleViolation::REASON, fn () => $transition->handle($this->owner, $task, $version, 'active'));
            $transition->handle($this->owner, $task, $version, 'active', 'QA found a regression');
            $this->assertNull(DB::selectOne('select completed_at from work.items where id = ?', [$task])->completed_at);
            $this->assertSame(1, $this->events('work.item.reopened', $task));
            $this->assertSame(5, $this->events('work.item.transitioned', $task));

            $payloads = (string) DB::selectOne("select string_agg(payload::text, ' ') as all from events.domain_outbox where aggregate_id = ?", [$task])->all;
            $this->assertStringNotContainsString('Ship it', $payloads);
            $this->assertStringNotContainsString('regression', $payloads);
        });
    }

    public function test_hierarchy_through_use_cases(): void
    {
        $this->as($this->owner, function () {
            $project = $this->project('HIE');
            $other = $this->project('HIO');
            $epic = $this->create($this->owner, $project, ['type' => 'epic', 'title' => 'Epic']);
            $task = $this->create($this->owner, $project, ['type' => 'task', 'title' => 'Task', 'parent_id' => $epic]);
            $subtask = $this->create($this->owner, $project, ['type' => 'task', 'title' => 'Subtask', 'parent_id' => $task]);
            $foreign = $this->create($this->owner, $other, ['type' => 'epic', 'title' => 'Other epic']);

            $this->assertRule(WorkRuleViolation::PARENT, fn () => $this->create($this->owner, $project, ['type' => 'task', 'title' => 'X', 'parent_id' => $foreign]));
            $this->assertRule(WorkRuleViolation::PARENT, fn () => $this->create($this->owner, $project, ['type' => 'task', 'title' => 'X', 'parent_id' => (string) Str::uuid()]));
            $this->assertRule(WorkRuleViolation::TYPE, fn () => $this->create($this->owner, $project, ['type' => 'epic', 'title' => 'X', 'parent_id' => $task]));
            $this->assertRule(WorkRuleViolation::HIERARCHY_CYCLE, fn () => $this->app->make(UpdateWorkItem::class)->handle($this->owner, $task, 0, ['parent_id' => $subtask]));
            $this->assertRule(WorkRuleViolation::TYPE, fn () => $this->app->make(UpdateWorkItem::class)->handle($this->owner, $task, 0, ['type' => 'bug']));
        });
    }

    public function test_classification_history_is_sanitized_and_versions_conflict(): void
    {
        $this->as($this->owner, function () {
            $project = $this->project('CLS');
            $item = $this->create($this->owner, $project, ['type' => 'bug', 'title' => 'Crash', 'description' => 'Initial secret details']);
            $update = $this->app->make(UpdateWorkItem::class);

            $version = $update->handle($this->owner, $item, 0, ['priority' => 'high', 'estimate' => 3.5, 'description' => 'Top secret new text']);
            $this->assertFailsWith(WorkVersionConflict::class, fn () => $update->handle($this->owner, $item, 0, ['priority' => 'low']));
            $this->assertSame('high', DB::selectOne('select priority from work.items where id = ?', [$item])->priority);

            $label = $this->app->make(CreateLabel::class)->handle($this->owner, $project, 'Backend');
            $foreignLabel = $this->app->make(CreateLabel::class)->handle($this->owner, $this->project('CLO'), 'Backend');
            $this->assertRule(WorkRuleViolation::LABEL, fn () => $this->app->make(CreateLabel::class)->handle($this->owner, $project, 'backend'));
            $labels = $this->app->make(ChangeItemLabels::class);
            $labels->add($this->owner, $item, $label);
            $labels->add($this->owner, $item, $label); // idempotent
            $this->assertRule(WorkRuleViolation::LABEL, fn () => $labels->add($this->owner, $item, $foreignLabel));
            $labels->remove($this->owner, $item, $label);
            $this->assertFailsWith(WorkRelationNotFound::class, fn () => $labels->remove($this->owner, $item, $label));

            $events = DB::select('select type, actor_membership_id::text as actor, changes::text as changes, occurred_at from work.item_events where item_id = ? order by occurred_at, id', [$item]);
            $this->assertSame(['created', 'updated', 'labels_changed', 'labels_changed'], array_column($events, 'type'));
            $this->assertSame($this->membershipId($this->owner), $events[1]->actor);
            $diff = json_decode($events[1]->changes, true);
            $this->assertEquals(['from' => 'none', 'to' => 'high'], $diff['priority']);
            $this->assertEquals(['from' => null, 'to' => '3.50'], $diff['estimate']);
            $this->assertEquals(['changed' => true, 'length' => 19], $diff['description']);
            $all = implode(' ', array_column($events, 'changes'));
            $this->assertStringNotContainsString('secret', $all);
            $this->assertSame($version, (int) DB::selectOne('select lock_version from work.items where id = ?', [$item])->lock_version);
        });
    }

    public function test_permissions_follow_the_matrix(): void
    {
        $developer = $this->member('developer');
        $assignee = $this->member('developer');
        $manager = $this->member('developer');
        $lead = $this->member('developer');
        $viewer = $this->member('viewer');
        $outsider = $this->member('developer');

        [$project, $hidden, $item, $teamItem] = $this->as($this->owner, function () use ($developer, $assignee, $manager, $lead, $viewer) {
            $project = $this->project('PRM');
            $hidden = $this->project('HID');
            foreach ([$developer, $assignee, $lead, $viewer] as $user) {
                $this->app->make(AddProjectMember::class)->handle($this->owner, $project, $this->membershipId($user));
            }
            $this->app->make(AddProjectMember::class)->handle($this->owner, $project, $this->membershipId($manager), 'manager');
            $item = $this->create($this->owner, $project, ['type' => 'task', 'title' => 'Assigned']);
            $teamItem = $this->create($this->owner, $project, ['type' => 'task', 'title' => 'Team item']);
            $this->assign($item, $assignee);
            $team = $this->app->make(CreateTeam::class)->handle($this->owner, 'Squad');
            $this->app->make(AssignTeamMember::class)->handle($this->owner, $team, $this->membershipId($lead), 'lead');
            DB::insert(
                'insert into work.item_team_links (organization_id, item_id, team_id, linked_by_membership_id) values (platform.current_organization_id(), ?, ?, ?)',
                [$teamItem, $team, $this->membershipId($this->owner)]
            );

            return [$project, $hidden, $item, $teamItem];
        });

        $rename = fn (User $actor, string $target) => $this->app->make(UpdateWorkItem::class)->handle($actor, $target, (int) DB::selectOne('select lock_version from work.items where id = ?', [$target])->lock_version, ['title' => 'Renamed']);

        $this->as($developer, function () use ($developer, $project, $hidden, $item, $rename) {
            $this->assertIsString($this->create($developer, $project, ['type' => 'task', 'title' => 'Mine']));
            $this->assertFailsWith(WorkOperationDenied::class, fn () => $rename($developer, $item));
            $this->assertFailsWith(WorkItemNotFound::class, fn () => $this->create($developer, $hidden, ['type' => 'task', 'title' => 'Hidden']));
        });
        $this->as($assignee, fn () => $this->assertIsInt($rename($assignee, $item)));
        $this->as($manager, fn () => $this->assertIsInt($rename($manager, $item)));
        $this->as($lead, function () use ($lead, $item, $teamItem, $rename) {
            $this->assertIsInt($rename($lead, $teamItem));
            $this->assertFailsWith(WorkOperationDenied::class, fn () => $rename($lead, $item));
        });
        $this->as($viewer, function () use ($viewer, $project, $item, $rename) {
            $this->assertFailsWith(WorkOperationDenied::class, fn () => $this->create($viewer, $project, ['type' => 'task', 'title' => 'Nope']));
            $this->assertFailsWith(WorkOperationDenied::class, fn () => $rename($viewer, $item));
        });
        $this->as($outsider, fn () => $this->assertFailsWith(WorkItemNotFound::class, fn () => $rename($outsider, $item)));

        $stranger = $this->user();
        $other = $this->app->make(CreateOrganization::class)->handle($stranger, 'Other Inc');
        $this->tenant->runFor($stranger, $other, fn () => $this->assertFailsWith(WorkItemNotFound::class, fn () => $this->app->make(UpdateWorkItem::class)->handle($stranger, $item, 0, ['title' => 'Stolen'])));
    }

    public function test_archived_projects_freeze_their_work(): void
    {
        $this->as($this->owner, function () {
            $project = $this->project('ARC');
            $item = $this->create($this->owner, $project, ['type' => 'task', 'title' => 'Frozen']);
            $this->app->make(UpdateProject::class)->handle($this->owner, $project, 0, ['status' => 'archived']);

            $this->assertRule(WorkRuleViolation::ARCHIVED, fn () => $this->create($this->owner, $project, ['type' => 'task', 'title' => 'New']));
            $this->assertRule(WorkRuleViolation::ARCHIVED, fn () => $this->app->make(UpdateWorkItem::class)->handle($this->owner, $item, 0, ['title' => 'Changed']));
        });
    }

    private function project(string $key): string
    {
        return $this->app->make(CreateProject::class)->handle($this->owner, $key, "Project {$key}");
    }

    /**
     * @param  array{type: string, title: string, description?: string|null, parent_id?: string|null, priority?: string}  $input
     */
    private function create(User $actor, string $project, array $input): string
    {
        return $this->app->make(CreateWorkItem::class)->handle($actor, $project, $input);
    }

    private function key(string $item): string
    {
        return (string) DB::selectOne('select key from work.items where id = ?', [$item])->key;
    }

    private function assign(string $item, User $user): void
    {
        DB::insert(
            "insert into work.item_assignments (organization_id, item_id, membership_id, role, assigned_by_membership_id)
             values (platform.current_organization_id(), ?, ?, 'collaborator', ?)",
            [$item, $this->membershipId($user), $this->membershipId($this->owner)]
        );
    }

    private function assertRule(string $rule, \Closure $operation): void
    {
        try {
            DB::transaction($operation);
            $this->fail('Expected WorkRuleViolation.');
        } catch (WorkRuleViolation $violation) {
            $this->assertSame($rule, $violation->getMessage());
        }
    }

    private function as(User $actor, \Closure $callback): mixed
    {
        return $this->tenant->runFor($actor, $this->organization, $callback);
    }

    private function user(): User
    {
        return User::factory()->create(['email' => Str::uuid().'@example.test']);
    }

    private function member(string $role): User
    {
        $user = $this->user();
        $ticket = $this->as($this->owner, fn () => $this->app->make(InviteMember::class)->handle($this->owner, $user->email, $role))->ticket;
        $this->app->make(AcceptInvitation::class)->handle($user, $this->organization, $ticket->reveal());

        return $user;
    }

    private function membershipId(User $user): string
    {
        return (string) DB::selectOne('select id::text as id from identity.organization_memberships where user_id = ?', [$user->getKey()])->id;
    }

    private function events(string $name, string $aggregateId): int
    {
        return (int) DB::selectOne('select count(*) as total from events.domain_outbox where event_name = ? and aggregate_id = ?', [$name, $aggregateId])->total;
    }

    /**
     * @param  class-string<\Throwable>  $expected
     */
    private function assertFailsWith(string $expected, \Closure $operation): void
    {
        try {
            DB::transaction($operation);
            $this->fail("Expected {$expected}.");
        } catch (\Throwable $exception) {
            $this->assertInstanceOf($expected, $exception, $exception->getMessage());
        }
    }
}
