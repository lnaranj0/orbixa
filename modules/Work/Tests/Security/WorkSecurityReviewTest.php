<?php

namespace Modules\Work\Tests\Security;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\IdentityTenancy\Application\AcceptInvitation;
use Modules\IdentityTenancy\Application\CreateOrganization;
use Modules\IdentityTenancy\Application\InviteMember;
use Modules\IdentityTenancy\Contracts\MembershipReferenceInvalid;
use Modules\IdentityTenancy\Infrastructure\Persistence\User;
use Modules\Platform\Contracts\Tenancy\OrganizationId;
use Modules\Platform\Contracts\Tenancy\TenantContext;
use Modules\Platform\Tests\Security\SecurityTestCase;
use Modules\Portfolio\Application\AddProjectMember;
use Modules\Portfolio\Application\CreateProject;
use Modules\Portfolio\Application\RemoveProjectMember;
use Modules\Teams\Application\AssignTeamMember;
use Modules\Teams\Application\CreateTeam;
use Modules\Work\Application\AssignWorkItem;
use Modules\Work\Application\CreateWorkItem;
use Modules\Work\Application\LinkItemTeam;
use Modules\Work\Application\UpdateWorkItem;
use Modules\Work\Contracts\Workflow\TransitionPolicy;
use Modules\Work\Contracts\WorkOperationDenied;

/**
 * Regressions for the 03 security review (M1, M2, B1, B5, B7).
 */
final class WorkSecurityReviewTest extends SecurityTestCase
{
    private TenantContext $tenant;

    private User $owner;

    private OrganizationId $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->app->make(TenantContext::class);
        $this->owner = $this->user();
        $this->organization = $this->app->make(CreateOrganization::class)->handle($this->owner, 'Work Review');
    }

    public function test_m1_viewers_and_reviewers_never_edit_even_when_assigned_or_leading(): void
    {
        $viewer = $this->member('viewer');
        $reviewer = $this->member('reviewer');
        $item = $this->as($this->owner, function () use ($viewer, $reviewer) {
            $project = $this->app->make(CreateProject::class)->handle($this->owner, 'VWR', 'Viewers');
            foreach ([$viewer, $reviewer] as $user) {
                $this->app->make(AddProjectMember::class)->handle($this->owner, $project, $this->membershipId($user));
            }
            $item = $this->app->make(CreateWorkItem::class)->handle($this->owner, $project, ['type' => 'task', 'title' => 'Read only']);
            $team = $this->app->make(CreateTeam::class)->handle($this->owner, 'Readers');
            $this->app->make(AssignTeamMember::class)->handle($this->owner, $team, $this->membershipId($viewer), 'lead');
            $this->app->make(LinkItemTeam::class)->link($this->owner, $item, $team);

            foreach ([$viewer, $reviewer] as $user) {
                $this->assertFailsWith(MembershipReferenceInvalid::class, fn () => $this->app->make(AssignWorkItem::class)->assign($this->owner, $item, $this->membershipId($user)));
            }

            return $item;
        });

        // Viewer leading an associated team: still no edit, transition or assignment.
        $reviewerMembership = $this->membershipId($reviewer);
        $this->as($viewer, function () use ($viewer, $reviewerMembership, $item) {
            $this->assertFailsWith(WorkOperationDenied::class, fn () => $this->app->make(UpdateWorkItem::class)->handle($viewer, $item, 0, ['title' => 'Nope']));
            $this->assertFailsWith(WorkOperationDenied::class, fn () => $this->app->make(AssignWorkItem::class)->assign($viewer, $item, $reviewerMembership));
        });
    }

    public function test_m2_long_dependency_chains_cannot_close_a_cycle(): void
    {
        $this->as($this->owner, function () {
            $project = $this->app->make(CreateProject::class)->handle($this->owner, 'CHN', 'Chain');
            $owner = $this->membershipId($this->owner);
            $workflow = $this->app->make(TransitionPolicy::class);
            $workflow->ensureWorkflow($project);
            DB::insert(
                "insert into work.items (organization_id, project_id, number, key, type, title, status, workflow_status_id, created_by_membership_id)
                 select platform.current_organization_id(), ?::uuid, n, 'CHN-' || n, 'task', 'Link ' || n, 'backlog', ?::uuid, ?::uuid from generate_series(1, 1003) n",
                [$project, $workflow->initialStatus($project)->id, $owner]
            );
            DB::insert(
                'insert into work.item_dependencies (organization_id, project_id, blocker_item_id, blocked_item_id, created_by_membership_id)
                 select platform.current_organization_id(), ?::uuid, a.id, b.id, ?::uuid
                   from work.items a join work.items b on b.project_id = a.project_id and b.number = a.number + 1
                  where a.project_id = ?::uuid order by a.number',
                [$project, $owner, $project]
            );
            $first = (string) DB::selectOne("select id::text as id from work.items where key = 'CHN-1'")->id;
            $last = (string) DB::selectOne("select id::text as id from work.items where key = 'CHN-1003'")->id;

            $this->assertSqlState('23514', fn (Connection $db) => $db->insert(
                'insert into work.item_dependencies (organization_id, project_id, blocker_item_id, blocked_item_id, created_by_membership_id)
                 values (platform.current_organization_id(), ?, ?, ?, ?)',
                [$project, $last, $first, $owner]
            ));
        });
    }

    public function test_b1_archiving_waits_for_a_concurrent_item_edit(): void
    {
        [$project, $item] = $this->as($this->owner, function () {
            $project = $this->app->make(CreateProject::class)->handle($this->owner, 'ARW', 'Archive race');

            return [$project, $this->app->make(CreateWorkItem::class)->handle($this->owner, $project, ['type' => 'task', 'title' => 'Racing'])];
        });
        config(['database.connections.pgsql_second' => config('database.connections.pgsql')]);
        $first = DB::connection('pgsql');
        $second = DB::connection('pgsql_second');

        $first->beginTransaction();
        $this->enter($first);
        $first->update("update work.items set title = 'Edited' where id = ?", [$item]);

        $second->beginTransaction();
        $this->enter($second);
        $second->statement("set local lock_timeout = '300ms'");
        try {
            $second->update("update portfolio.projects set status = 'archived' where id = ?", [$project]);
            $this->fail('Archiving must wait for the item edit.');
        } catch (QueryException $blocked) {
            $this->assertSame('55P03', $blocked->errorInfo[0]);
        } finally {
            $second->rollBack();
            $first->rollBack();
        }
    }

    public function test_b5_b7_replays_are_reauthorized_and_invisible_characters_rejected(): void
    {
        $developer = $this->member('developer');
        $project = $this->as($this->owner, function () use ($developer) {
            $project = $this->app->make(CreateProject::class)->handle($this->owner, 'RPL', 'Replay');
            $this->app->make(AddProjectMember::class)->handle($this->owner, $project, $this->membershipId($developer));

            return $project;
        });
        $url = "/api/v1/organizations/{$this->organization->value}/projects/{$project}/items";
        $headers = ['Idempotency-Key' => 'replay-0001'];
        $body = ['type' => 'task', 'title' => 'Replayed'];

        $this->actingAs($developer)->postJson($url, ['type' => 'task', 'title' => "Fake \u{202E}1-XPR"])->assertStatus(422);
        $this->postJson($url, $body, $headers)->assertCreated();
        $this->postJson($url, $body, $headers)->assertCreated()->assertJsonPath('data.key', 'RPL-1');

        $this->as($this->owner, fn () => $this->app->make(RemoveProjectMember::class)->handle($this->owner, $project, $this->membershipId($developer)));
        $this->actingAs($developer)->postJson($url, $body, $headers)->assertNotFound();
    }

    private function enter(Connection $db): void
    {
        $db->selectOne("select set_config('app.organization_id', ?, true)", [$this->organization->value]);
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
        return $this->as($this->owner, fn () => (string) DB::selectOne(
            'select id::text as id from identity.organization_memberships where user_id = ?',
            [$user->getKey()]
        )->id);
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
