<?php

namespace Modules\Teams\Tests\Security;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Modules\Access\Contracts\Scope;
use Modules\IdentityTenancy\Application\AcceptInvitation;
use Modules\IdentityTenancy\Application\CreateOrganization;
use Modules\IdentityTenancy\Application\InviteMember;
use Modules\IdentityTenancy\Application\RevokeMembership;
use Modules\IdentityTenancy\Contracts\MembershipReferenceInvalid;
use Modules\IdentityTenancy\Infrastructure\Persistence\User;
use Modules\Platform\Contracts\Tenancy\OrganizationId;
use Modules\Platform\Contracts\Tenancy\TenantContext;
use Modules\Platform\Tests\Security\SecurityTestCase;
use Modules\Teams\Application\ArchiveTeam;
use Modules\Teams\Application\AssignTeamMember;
use Modules\Teams\Application\CreateTeam;
use Modules\Teams\Application\MoveTeam;
use Modules\Teams\Application\RemoveTeamMember;
use Modules\Teams\Contracts\HierarchyViolation;
use Modules\Teams\Contracts\TeamDirectory;
use Modules\Teams\Contracts\TeamMemberNotFound;
use Modules\Teams\Contracts\TeamNotFound;
use Modules\Teams\Contracts\TeamOperationDenied;

/**
 * 02 increment 3: Teams use cases on real PostgreSQL as the runtime role.
 */
final class TeamsUseCasesTest extends SecurityTestCase
{
    private TenantContext $tenant;

    private User $owner;

    private OrganizationId $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->app->make(TenantContext::class);
        $this->owner = $this->user();
        $this->organization = $this->app->make(CreateOrganization::class)->handle($this->owner, 'Teams Inc');
    }

    public function test_owner_creates_and_moves_teams_with_history_and_events(): void
    {
        $this->as($this->owner, function () {
            $parent = $this->create('Engineering');
            $other = $this->create('Product');
            $child = $this->create('Backend', $parent);

            $this->app->make(MoveTeam::class)->handle($this->owner, $child, $other);

            $change = DB::selectOne('select old_parent_team_id::text as old, new_parent_team_id::text as new from teams.team_parent_changes where team_id = ?', [$child]);
            $this->assertSame([$parent, $other], [$change->old, $change->new]);
            $this->assertSame(1, $this->events('team.moved', $child));
            $this->assertSame(3, (int) DB::selectOne("select count(*) as total from events.domain_outbox where event_name = 'team.created'")->total);
            $this->assertSame([$child], $this->app->make(TeamDirectory::class)->descendantsOf($other));
        });
    }

    public function test_hierarchy_violations_are_domain_errors(): void
    {
        $this->as($this->owner, function () {
            $a = $this->create('A');
            $b = $this->create('B', $a);

            $this->assertViolation(HierarchyViolation::CYCLE, fn () => $this->app->make(MoveTeam::class)->handle($this->owner, $a, $b));
            $this->assertViolation(HierarchyViolation::ACTIVE_CHILDREN, fn () => $this->app->make(ArchiveTeam::class)->handle($this->owner, $a));
            $this->assertViolation(HierarchyViolation::PARENT, fn () => $this->create('Ghost', (string) Str::uuid()));

            $deep = $b;
            foreach (['C', 'D', 'E'] as $name) {
                $deep = $this->create($name, $deep);
            }
            $this->assertViolation(HierarchyViolation::DEPTH, fn () => $this->create('F', $deep));
        });
    }

    public function test_foreign_parent_is_rejected_like_a_missing_one(): void
    {
        $otherOwner = $this->user();
        $other = $this->app->make(CreateOrganization::class)->handle($otherOwner, 'Other Inc');
        $foreignTeam = $this->tenant->runFor($otherOwner, $other, fn () => $this->app->make(CreateTeam::class)->handle($otherOwner, 'Foreign'));

        $this->as($this->owner, function () use ($foreignTeam) {
            $this->assertViolation(HierarchyViolation::PARENT, fn () => $this->create('Cross', $foreignTeam));
            $this->expectException(TeamNotFound::class);
            $this->app->make(ArchiveTeam::class)->handle($this->owner, $foreignTeam);
        });
    }

    public function test_members_without_teams_manage_get_403_on_their_team_and_404_elsewhere(): void
    {
        $developer = $this->member('developer');
        [$mine, $notMine] = $this->as($this->owner, function () use ($developer) {
            $mine = $this->create('Mine');
            $notMine = $this->create('Not mine');
            $this->app->make(AssignTeamMember::class)->handle($this->owner, $mine, $this->membershipId($developer));

            return [$mine, $notMine];
        });

        $this->as($developer, function () use ($developer, $mine, $notMine) {
            $this->assertFailsWith(TeamOperationDenied::class, fn () => $this->app->make(CreateTeam::class)->handle($developer, 'Rogue'));
            $this->assertFailsWith(TeamOperationDenied::class, fn () => $this->app->make(ArchiveTeam::class)->handle($developer, $mine));
            $this->assertFailsWith(TeamNotFound::class, fn () => $this->app->make(ArchiveTeam::class)->handle($developer, $notMine));
        });
    }

    public function test_assignment_rules(): void
    {
        $developer = $this->member('developer');
        $admin = $this->member('org_admin');

        $this->as($this->owner, function () use ($developer) {
            $team = $this->create('Squad');
            $assign = $this->app->make(AssignTeamMember::class);

            $this->assertFailsWith(TeamOperationDenied::class, fn () => $assign->handle($this->owner, $team, $this->membershipId($this->owner)));
            $this->assertFailsWith(MembershipReferenceInvalid::class, fn () => $assign->handle($this->owner, $team, (string) Str::uuid()));
            $this->assertFailsWith(MembershipReferenceInvalid::class, fn () => $assign->handle($this->owner, $team, 'not-a-uuid'));

            $assign->handle($this->owner, $team, $this->membershipId($developer), 'lead');
            $assign->handle($this->owner, $team, $this->membershipId($developer), 'lead'); // idempotent
            $this->assertSame(1, (int) DB::selectOne('select count(*) as total from teams.team_members where team_id = ?', [$team])->total);
            $this->assertSame(1, $this->events('team.member.assigned', $team));
            $payload = (string) DB::selectOne("select payload::text as payload from events.domain_outbox where event_name = 'team.member.assigned' and aggregate_id = ?", [$team])->payload;
            $this->assertStringNotContainsString('@', $payload);

            $this->app->make(RemoveTeamMember::class)->handle($this->owner, $team, $this->membershipId($developer));
            $row = DB::selectOne('select removed_reason, removed_at from teams.team_members where team_id = ?', [$team]);
            $this->assertSame('removed', $row->removed_reason);
            $this->assertFailsWith(TeamMemberNotFound::class, fn () => $this->app->make(RemoveTeamMember::class)->handle($this->owner, $team, $this->membershipId($developer)));
        });

        // An admin holds teams.manage at organization level and may appoint leads too.
        $this->as($admin, fn () => $this->assertIsString($this->create('Admin team')));
    }

    public function test_promoting_an_existing_member_to_lead_takes_effect_with_history(): void
    {
        $developer = $this->member('developer');
        $parentLead = $this->member('developer');
        $developerMembership = $this->as($this->owner, fn () => $this->membershipId($developer));

        $team = $this->as($this->owner, function () use ($developerMembership, $parentLead) {
            $parent = $this->create('Parent');
            $team = $this->create('Child', $parent);
            $assign = $this->app->make(AssignTeamMember::class);
            $assign->handle($this->owner, $parent, $this->membershipId($parentLead), 'lead');
            $assign->handle($this->owner, $team, $developerMembership);
            $assign->handle($this->owner, $team, $developerMembership, 'lead'); // was a silent no-op

            return $team;
        });

        $this->as($developer, fn () => $this->assertTrue(Gate::forUser($developer)->allows('teams.manage', [Scope::team($team)])));

        // A lead of the parent manages the child team but cannot demote its lead (D14).
        $this->as($parentLead, fn () => $this->assertFailsWith(TeamOperationDenied::class, fn () => $this->app->make(AssignTeamMember::class)->handle($parentLead, $team, $developerMembership)));

        $this->as($this->owner, function () use ($team, $developerMembership) {
            $rows = DB::select('select role, removed_reason from teams.team_members where team_id = ? and membership_id = ? order by assigned_at, id', [$team, $developerMembership]);
            $this->assertSame([['member', 'removed'], ['lead', null]], array_map(fn ($r) => [$r->role, $r->removed_reason], $rows));
            $this->assertSame(2, $this->events('team.member.assigned', $team));
            $this->assertSame(1, $this->events('team.member.removed', $team));
        });
    }

    public function test_archived_team_rejects_members(): void
    {
        $developer = $this->member('developer');
        $this->as($this->owner, function () use ($developer) {
            $team = $this->create('Legacy');
            $this->app->make(ArchiveTeam::class)->handle($this->owner, $team);
            $this->assertViolation(HierarchyViolation::ARCHIVED, fn () => $this->app->make(AssignTeamMember::class)->handle($this->owner, $team, $this->membershipId($developer)));
        });
    }

    public function test_revoking_organization_membership_closes_team_memberships(): void
    {
        $developer = $this->member('developer');
        $developerMembership = $this->as($this->owner, fn () => $this->membershipId($developer));

        $team = $this->as($this->owner, function () use ($developerMembership) {
            $team = $this->create('Revocation');
            $this->app->make(AssignTeamMember::class)->handle($this->owner, $team, $developerMembership);
            $this->app->make(RevokeMembership::class)->handle($this->owner, $developerMembership);

            return $team;
        });

        $this->as($this->owner, function () use ($team, $developerMembership) {
            $row = DB::selectOne('select removed_reason from teams.team_members where team_id = ? and membership_id = ?', [$team, $developerMembership]);
            $this->assertSame('membership_revoked', $row->removed_reason);
            $this->assertSame([], $this->app->make(TeamDirectory::class)->activeTeamIdsOf($developerMembership));
        });
    }

    private function create(string $name, ?string $parent = null): string
    {
        return $this->app->make(CreateTeam::class)->handle($this->owner, $name, $parent);
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

    private function assertViolation(string $message, \Closure $operation): void
    {
        try {
            DB::transaction($operation);
            $this->fail('Expected HierarchyViolation.');
        } catch (HierarchyViolation $violation) {
            $this->assertSame($message, $violation->getMessage());
        }
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
