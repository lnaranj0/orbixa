<?php

namespace Modules\Portfolio\Tests\Security;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
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
use Modules\Portfolio\Contracts\ProjectDirectory;
use Modules\Portfolio\Contracts\ProjectOperationDenied;
use Modules\Teams\Application\AssignTeamMember;
use Modules\Teams\Application\CreateTeam;
use Modules\Teams\Application\RemoveTeamMember;
use Modules\Teams\Contracts\TeamOperationDenied;

/**
 * Regressions for the 02 security review (H1, M1, M2, B1-B6), on real
 * PostgreSQL as the runtime role.
 */
final class SecurityReviewTest extends SecurityTestCase
{
    private TenantContext $tenant;

    private User $owner;

    private OrganizationId $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->app->make(TenantContext::class);
        $this->owner = $this->user();
        $this->organization = $this->app->make(CreateOrganization::class)->handle($this->owner, 'Review Inc');
    }

    public function test_h1_a_lead_cannot_remove_other_leads(): void
    {
        $lead = $this->member('developer');
        $subLead = $this->member('developer');
        $coLead = $this->member('developer');
        $member = $this->member('developer');

        [$a, $b] = $this->as($this->owner, function () use ($lead, $subLead, $coLead, $member) {
            $a = $this->app->make(CreateTeam::class)->handle($this->owner, 'A');
            $b = $this->app->make(CreateTeam::class)->handle($this->owner, 'B', $a);
            $assign = $this->app->make(AssignTeamMember::class);
            $assign->handle($this->owner, $a, $this->membershipId($lead), 'lead');
            $assign->handle($this->owner, $a, $this->membershipId($coLead), 'lead');
            $assign->handle($this->owner, $b, $this->membershipId($subLead), 'lead');
            $assign->handle($this->owner, $b, $this->membershipId($member));

            return [$a, $b];
        });

        $this->as($lead, function () use ($lead, $a, $b, $subLead, $coLead, $member) {
            $remove = $this->app->make(RemoveTeamMember::class);
            $this->assertFailsWith(TeamOperationDenied::class, fn () => $remove->handle($lead, $b, $this->membershipId($subLead)));
            $this->assertFailsWith(TeamOperationDenied::class, fn () => $remove->handle($lead, $a, $this->membershipId($coLead)));
            $remove->handle($lead, $b, $this->membershipId($member)); // plain members are fine
        });

        $this->as($this->owner, fn () => $this->assertSame(2, (int) DB::selectOne("select count(*) as total from teams.team_members where role = 'lead' and removed_at is null and team_id in (?, ?) and membership_id in (?, ?)", [$a, $b, $this->membershipId($subLead), $this->membershipId($coLead)])->total));
    }

    public function test_m2_scoped_manager_cannot_lower_confidentiality(): void
    {
        $manager = $this->member('developer');
        [$restricted, $standard] = $this->as($this->owner, function () use ($manager) {
            $restricted = $this->app->make(CreateProject::class)->handle($this->owner, 'RES', 'Restricted', 'general', 'restricted');
            $standard = $this->app->make(CreateProject::class)->handle($this->owner, 'STA', 'Standard');
            $this->app->make(AddProjectMember::class)->handle($this->owner, $restricted, $this->membershipId($manager), 'manager');
            $this->app->make(AddProjectMember::class)->handle($this->owner, $standard, $this->membershipId($manager), 'manager');

            return [$restricted, $standard];
        });

        $this->as($manager, function () use ($manager, $restricted, $standard) {
            $update = $this->app->make(UpdateProject::class);
            $this->assertFailsWith(ProjectOperationDenied::class, fn () => $update->handle($manager, $restricted, 0, ['confidentiality' => 'standard']));
            $this->assertSame(1, $update->handle($manager, $standard, 0, ['confidentiality' => 'restricted'])); // narrowing is fine
        });

        $this->as($this->owner, fn () => $this->assertSame(1, $this->app->make(UpdateProject::class)->handle($this->owner, $restricted, 0, ['confidentiality' => 'standard'])));
    }

    public function test_m1_b4_listings_redact_and_access_is_audited_once_per_window(): void
    {
        $admin = $this->member('org_admin');
        $project = $this->as($this->owner, fn () => $this->app->make(CreateProject::class)->handle($this->owner, 'SEC', 'Secret', 'general', 'restricted', 'Sensitive description'));

        // The owner is a direct member (creator): full listing.
        $this->as($this->owner, fn () => $this->assertSame('Sensitive description', $this->app->make(ProjectDirectory::class)->page($this->owner, [], null, 10)[0]->description));

        $this->as($admin, function () use ($admin, $project) {
            $directory = $this->app->make(ProjectDirectory::class);
            $this->assertNull($directory->page($admin, ['confidentiality' => 'restricted'], null, 10)[0]->description);
            $this->assertNull($directory->visible($admin)[0]->description);
            $this->assertSame(0, $this->events('audit.sensitive_access.granted', $project));

            $this->assertSame('Sensitive description', $directory->find($admin, $project)?->description);
            $directory->find($admin, $project);
            $this->assertSame(1, $this->events('audit.sensitive_access.granted', $project)); // deduplicated

            $this->app->make(UpdateProject::class)->handle($admin, $project, 0, ['name' => 'Renamed']);
            $this->assertSame(2, $this->events('audit.sensitive_access.granted', $project)); // update is its own operation
        });
    }

    public function test_b1_archiving_a_team_waits_for_a_concurrent_link(): void
    {
        [$project, $team] = $this->as($this->owner, fn () => [
            $this->app->make(CreateProject::class)->handle($this->owner, 'LCK', 'Lock'),
            $this->app->make(CreateTeam::class)->handle($this->owner, 'Lock team'),
        ]);
        $ownerMembership = $this->as($this->owner, fn () => $this->membershipId($this->owner));

        [$first, $second] = $this->connections();
        $first->beginTransaction();
        $this->enter($first);
        $first->insert(
            'insert into portfolio.project_team_links (organization_id, project_id, team_id, linked_by_membership_id)
             values (platform.current_organization_id(), ?::uuid, ?::uuid, ?::uuid)',
            [$project, $team, $ownerMembership]
        );

        $this->assertBlocked($second, "update teams.teams set status = 'archived', archived_at = now() where id = ?::uuid", [$team]);
        $this->assertBlocked($second, "update portfolio.projects set status = 'archived' where id = ?::uuid", [$project]);
        $first->rollBack();
    }

    public function test_b2_demotion_and_revocation_wait_for_the_actors_operation(): void
    {
        $manager = $this->member('developer');
        $developer = $this->member('developer');
        $project = $this->as($this->owner, function () use ($manager) {
            $project = $this->app->make(CreateProject::class)->handle($this->owner, 'ACT', 'Actor');
            $this->app->make(AddProjectMember::class)->handle($this->owner, $project, $this->membershipId($manager), 'manager');

            return $project;
        });
        $managerMembership = $this->as($this->owner, fn () => $this->membershipId($manager));
        $developerMembership = $this->as($this->owner, fn () => $this->membershipId($developer));

        [, $second] = $this->connections();
        DB::beginTransaction();
        try {
            $this->as($manager, fn () => $this->app->make(AddProjectMember::class)->handle($manager, $project, $developerMembership));

            $this->assertBlocked($second, 'select 1 from identity.organization_memberships where id = ?::uuid for update', [$managerMembership]);
            $this->assertBlocked($second, "select 1 from portfolio.project_members where membership_id = ?::uuid and role = 'manager' for update", [$managerMembership]);
        } finally {
            DB::rollBack();
        }
    }

    /**
     * @return array{Connection, Connection}
     */
    private function connections(): array
    {
        config(['database.connections.pgsql_second' => config('database.connections.pgsql')]);

        return [DB::connection('pgsql'), DB::connection('pgsql_second')];
    }

    private function enter(Connection $db): void
    {
        $db->selectOne("select set_config('app.organization_id', ?, true)", [$this->organization->value]);
    }

    /**
     * @param  list<string>  $bindings
     */
    private function assertBlocked(Connection $db, string $sql, array $bindings): void
    {
        $db->beginTransaction();
        $this->enter($db);
        $db->statement("set local lock_timeout = '300ms'");
        try {
            $db->statement($sql, $bindings);
            $this->fail('Expected the statement to wait for the first transaction.');
        } catch (QueryException $blocked) {
            $this->assertSame('55P03', $blocked->errorInfo[0]);
        } finally {
            $db->rollBack();
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
