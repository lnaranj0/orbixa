<?php

namespace Modules\Work\Tests\Security;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\IdentityTenancy\Application\AcceptInvitation;
use Modules\IdentityTenancy\Application\CreateOrganization;
use Modules\IdentityTenancy\Application\InviteMember;
use Modules\IdentityTenancy\Application\RevokeMembership;
use Modules\IdentityTenancy\Contracts\MembershipReferenceInvalid;
use Modules\IdentityTenancy\Infrastructure\Persistence\User;
use Modules\Platform\Contracts\Tenancy\OrganizationId;
use Modules\Platform\Contracts\Tenancy\TenantContext;
use Modules\Platform\Tests\Security\SecurityTestCase;
use Modules\Portfolio\Application\AddProjectMember;
use Modules\Portfolio\Application\CreateProject;
use Modules\Portfolio\Application\RemoveProjectMember;
use Modules\Teams\Application\ArchiveTeam;
use Modules\Teams\Application\AssignTeamMember;
use Modules\Teams\Application\CreateTeam;
use Modules\Work\Application\AssignWorkItem;
use Modules\Work\Application\ChangeDependencies;
use Modules\Work\Application\CreateWorkItem;
use Modules\Work\Application\LinkItemTeam;
use Modules\Work\Application\UpdateWorkItem;
use Modules\Work\Contracts\WorkItemNotFound;
use Modules\Work\Contracts\WorkOperationDenied;
use Modules\Work\Contracts\WorkRelationNotFound;
use Modules\Work\Contracts\WorkRuleViolation;

/**
 * 03 increment 5: assignments, team associations and dependencies (spec
 * "Asignaciones y subequipos", "Dependencias", "Visibilidad heredada").
 */
final class WorkRelationsTest extends SecurityTestCase
{
    private TenantContext $tenant;

    private User $owner;

    private OrganizationId $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->app->make(TenantContext::class);
        $this->owner = $this->user();
        $this->organization = $this->app->make(CreateOrganization::class)->handle($this->owner, 'Relations Inc');
    }

    public function test_assignment_rules(): void
    {
        [$a, $b, $c] = [$this->member('developer'), $this->member('developer'), $this->member('developer')];
        $outsider = $this->member('developer'); // in the organization, not in the project
        $stranger = $this->user();
        $other = $this->app->make(CreateOrganization::class)->handle($stranger, 'Other Inc');
        $foreignMembership = $this->tenant->runFor($stranger, $other, fn () => (string) DB::selectOne('select id::text as id from identity.organization_memberships where user_id = ?', [$stranger->getKey()])->id);

        [$project, $item] = $this->as($this->owner, function () use ($a, $b, $c) {
            $project = $this->app->make(CreateProject::class)->handle($this->owner, 'ASG', 'Assignments');
            foreach ([$a, $b, $c] as $user) {
                $this->app->make(AddProjectMember::class)->handle($this->owner, $project, $this->membershipId($user));
            }

            return [$project, $this->app->make(CreateWorkItem::class)->handle($this->owner, $project, ['type' => 'task', 'title' => 'Assign me'])];
        });

        $this->as($this->owner, function () use ($item, $a, $b, $c, $outsider, $foreignMembership) {
            $assign = $this->app->make(AssignWorkItem::class);
            $assign->assign($this->owner, $item, $this->membershipId($a), 'owner');
            $assign->assign($this->owner, $item, $this->membershipId($b));
            $assign->assign($this->owner, $item, $this->membershipId($b)); // idempotent
            $assign->assign($this->owner, $item, $this->membershipId($c), 'owner'); // replaces a

            $active = DB::select('select membership_id::text as m, role from work.item_assignments where item_id = ? and removed_at is null order by role desc', [$item]);
            $this->assertSame([[$this->membershipId($c), 'owner'], [$this->membershipId($b), 'collaborator']], array_map(fn ($r) => [$r->m, $r->role], $active));
            $this->assertSame('replaced', DB::selectOne('select removed_reason from work.item_assignments where item_id = ? and membership_id = ?', [$item, $this->membershipId($a)])->removed_reason);

            $this->assertFailsWith(WorkOperationDenied::class, fn () => $assign->assign($this->owner, $item, $this->membershipId($this->owner)));
            foreach ([$this->membershipId($outsider), $foreignMembership, (string) Str::uuid(), 'nope'] as $invalid) {
                $this->assertFailsWith(MembershipReferenceInvalid::class, fn () => $assign->assign($this->owner, $item, $invalid));
            }
            $this->assertFailsWith(WorkRelationNotFound::class, fn () => $assign->unassign($this->owner, $item, $this->membershipId($a)));
            $this->assertSame(3, $this->events('work.item.assigned', $item));
        });

        // A collaborator edits but cannot assign (no work.assign).
        $this->as($b, function () use ($b, $item, $a) {
            $this->assertIsInt($this->app->make(UpdateWorkItem::class)->handle($b, $item, 0, ['priority' => 'high']));
            $this->assertFailsWith(WorkOperationDenied::class, fn () => $this->app->make(AssignWorkItem::class)->assign($b, $item, $this->membershipId($a)));
        });

        // Losing the project makes the item invisible, even if still assigned.
        $this->as($this->owner, fn () => $this->app->make(RemoveProjectMember::class)->handle($this->owner, $project, $this->membershipId($b)));
        $this->as($b, fn () => $this->assertFailsWith(WorkItemNotFound::class, fn () => $this->app->make(UpdateWorkItem::class)->handle($b, $item, 1, ['priority' => 'low'])));
    }

    public function test_revocation_closes_assignments(): void
    {
        $developer = $this->member('developer');
        $item = $this->as($this->owner, function () use ($developer) {
            $project = $this->app->make(CreateProject::class)->handle($this->owner, 'REV', 'Revocation');
            $this->app->make(AddProjectMember::class)->handle($this->owner, $project, $this->membershipId($developer));
            $item = $this->app->make(CreateWorkItem::class)->handle($this->owner, $project, ['type' => 'task', 'title' => 'Revoked']);
            $this->app->make(AssignWorkItem::class)->assign($this->owner, $item, $this->membershipId($developer), 'owner');

            return $item;
        });
        $membership = $this->as($this->owner, fn () => $this->membershipId($developer));
        $this->as($this->owner, fn () => $this->app->make(RevokeMembership::class)->handle($this->owner, $membership));

        $this->as($this->owner, fn () => $this->assertSame('membership_revoked', DB::selectOne('select removed_reason from work.item_assignments where item_id = ?', [$item])->removed_reason));
    }

    public function test_team_associations_and_lead_permissions(): void
    {
        $lead = $this->member('developer');
        $assignee = $this->member('developer');
        [$item, $team, $archived] = $this->as($this->owner, function () use ($lead, $assignee) {
            $project = $this->app->make(CreateProject::class)->handle($this->owner, 'TMS', 'Teams');
            $this->app->make(AddProjectMember::class)->handle($this->owner, $project, $this->membershipId($lead));
            $this->app->make(AddProjectMember::class)->handle($this->owner, $project, $this->membershipId($assignee));
            $team = $this->app->make(CreateTeam::class)->handle($this->owner, 'Squad');
            $archived = $this->app->make(CreateTeam::class)->handle($this->owner, 'Old');
            $this->app->make(ArchiveTeam::class)->handle($this->owner, $archived);
            $this->app->make(AssignTeamMember::class)->handle($this->owner, $team, $this->membershipId($lead), 'lead');

            return [$this->app->make(CreateWorkItem::class)->handle($this->owner, $project, ['type' => 'task', 'title' => 'Team work']), $team, $archived];
        });
        $foreignTeam = $this->foreignTeam();

        $this->as($lead, fn () => $this->assertFailsWith(WorkOperationDenied::class, fn () => $this->app->make(AssignWorkItem::class)->assign($lead, $item, $this->membershipId($assignee))));

        $this->as($this->owner, function () use ($item, $team, $archived, $foreignTeam) {
            $link = $this->app->make(LinkItemTeam::class);
            $link->link($this->owner, $item, $team);
            $link->link($this->owner, $item, $team); // idempotent
            foreach ([$archived, $foreignTeam, (string) Str::uuid(), 'nope'] as $invalid) {
                $this->assertRule(WorkRuleViolation::TEAM, fn () => $link->link($this->owner, $item, $invalid));
            }
        });

        // Now the item belongs to the lead's team ("Equipo propio").
        $this->as($lead, fn () => $this->app->make(AssignWorkItem::class)->assign($lead, $item, $this->membershipId($assignee)));
        $this->as($this->owner, function () use ($item, $team) {
            $this->app->make(LinkItemTeam::class)->unlink($this->owner, $item, $team);
            $this->assertFailsWith(WorkRelationNotFound::class, fn () => $this->app->make(LinkItemTeam::class)->unlink($this->owner, $item, $team));
        });
    }

    public function test_dependencies(): void
    {
        $this->as($this->owner, function () {
            $project = $this->app->make(CreateProject::class)->handle($this->owner, 'DEP', 'Deps');
            $other = $this->app->make(CreateProject::class)->handle($this->owner, 'DPO', 'Other');
            $create = fn (string $p, string $title) => $this->app->make(CreateWorkItem::class)->handle($this->owner, $p, ['type' => 'task', 'title' => $title]);
            [$a, $b, $c] = [$create($project, 'A'), $create($project, 'B'), $create($project, 'C')];
            $foreign = $create($other, 'Foreign');
            $deps = $this->app->make(ChangeDependencies::class);

            $deps->add($this->owner, $b, $a); // A blocks B
            $deps->add($this->owner, $c, $b); // B blocks C
            $deps->add($this->owner, $c, $b); // idempotent
            $this->assertRule(WorkRuleViolation::DEPENDENCY_CYCLE, fn () => $deps->add($this->owner, $a, $c));
            $this->assertRule(WorkRuleViolation::DEPENDENCY_CYCLE, fn () => $deps->add($this->owner, $a, $a));
            $this->assertRule(WorkRuleViolation::DEPENDENCY, fn () => $deps->add($this->owner, $a, $foreign));
            $this->assertRule(WorkRuleViolation::DEPENDENCY, fn () => $deps->add($this->owner, $a, (string) Str::uuid()));
            $this->assertSame(1, $this->events('work.item.blocked', $c));

            $deps->remove($this->owner, $c, $b);
            $this->assertFailsWith(WorkRelationNotFound::class, fn () => $deps->remove($this->owner, $c, $b));
        });
    }

    private function foreignTeam(): string
    {
        $stranger = $this->user();
        $other = $this->app->make(CreateOrganization::class)->handle($stranger, 'Foreign Teams');

        return $this->tenant->runFor($stranger, $other, fn () => $this->app->make(CreateTeam::class)->handle($stranger, 'Foreign'));
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
