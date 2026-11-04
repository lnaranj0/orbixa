<?php

namespace Modules\Portfolio\Tests\Security;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Modules\Access\Contracts\Scope;
use Modules\IdentityTenancy\Application\AcceptInvitation;
use Modules\IdentityTenancy\Application\CreateOrganization;
use Modules\IdentityTenancy\Application\InviteMember;
use Modules\IdentityTenancy\Application\RevokeMembership;
use Modules\IdentityTenancy\Infrastructure\Persistence\User;
use Modules\Platform\Contracts\Tenancy\OrganizationId;
use Modules\Platform\Contracts\Tenancy\TenantContext;
use Modules\Platform\Tests\Security\SecurityTestCase;
use Modules\Portfolio\Application\CreateProject;
use Modules\Portfolio\Application\UpdateProject;
use Modules\Portfolio\Contracts\ProjectDirectory;
use Modules\Portfolio\Contracts\ProjectKeyUnavailable;
use Modules\Portfolio\Contracts\ProjectNotFound;
use Modules\Portfolio\Contracts\ProjectOperationDenied;
use Modules\Portfolio\Contracts\ProjectSummary;
use Modules\Portfolio\Contracts\ProjectVersionConflict;
use Modules\Portfolio\Contracts\ProjectVisibility;
use Modules\Teams\Application\AssignTeamMember;
use Modules\Teams\Application\CreateTeam;

/**
 * 02 increment 6a: the single visibility predicate (D1, D2, D3, D5), project
 * creation/update and the project.manager scoped role, on real PostgreSQL as
 * the runtime role. Members and links are inserted directly until 6b adds
 * their use cases.
 */
final class ProjectVisibilityTest extends SecurityTestCase
{
    private TenantContext $tenant;

    private User $owner;

    private OrganizationId $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->app->make(TenantContext::class);
        $this->owner = $this->user();
        $this->organization = $this->app->make(CreateOrganization::class)->handle($this->owner, 'Portfolio Inc');
    }

    public function test_visibility_matrix(): void
    {
        $admin = $this->member('org_admin');
        $viewer = $this->member('viewer');
        $teamMember = $this->member('developer');
        $subteamMember = $this->member('developer');
        $direct = $this->member('developer');

        [$standard, $restricted] = $this->as($this->owner, function () use ($teamMember, $subteamMember, $direct) {
            $parent = $this->app->make(CreateTeam::class)->handle($this->owner, 'Parent');
            $child = $this->app->make(CreateTeam::class)->handle($this->owner, 'Child', $parent);
            $this->app->make(AssignTeamMember::class)->handle($this->owner, $parent, $this->membershipId($teamMember));
            $this->app->make(AssignTeamMember::class)->handle($this->owner, $child, $this->membershipId($subteamMember));

            $standard = $this->create('STD', 'standard');
            $restricted = $this->create('RST', 'restricted');
            $this->link($standard, $parent);
            $this->link($restricted, $parent);
            $this->addMember($restricted, $direct, 'member');

            return [$standard, $restricted];
        });

        $this->assertSees($this->owner, ['RST', 'STD']);
        $this->assertSees($admin, ['RST', 'STD']);
        $this->assertSees($direct, ['RST']);
        $this->assertSees($teamMember, ['STD']); // restricted needs a direct membership
        $this->assertSees($subteamMember, []);   // no inheritance from the parent team (D2)
        $this->assertSees($viewer, []);

        $this->as($teamMember, function () use ($teamMember, $restricted, $standard) {
            $directory = $this->app->make(ProjectDirectory::class);
            $this->assertNull($directory->find($teamMember, $restricted));
            $this->assertSame('STD', $directory->find($teamMember, $standard)?->key);
            $this->assertNull($directory->find($teamMember, 'not-a-uuid'));
        });
    }

    public function test_other_organization_cannot_see_or_update_by_uuid(): void
    {
        $project = $this->as($this->owner, fn () => $this->create('ORBX', 'standard'));

        $stranger = $this->user();
        $other = $this->app->make(CreateOrganization::class)->handle($stranger, 'Other Inc');

        $this->tenant->runFor($stranger, $other, function () use ($stranger, $project) {
            $this->assertNull($this->app->make(ProjectDirectory::class)->find($stranger, $project));
            $this->assertFalse($this->app->make(ProjectVisibility::class)->canView($stranger, $project));
            $this->assertFailsWith(ProjectNotFound::class, fn () => $this->update($stranger, $project, 0, ['name' => 'Stolen']));
        });

        $this->as($this->owner, fn () => $this->assertSame('ORBX', $this->app->make(ProjectDirectory::class)->find($this->owner, $project)?->key));
    }

    public function test_visible_without_manage_is_403_and_invisible_is_404(): void
    {
        $developer = $this->member('developer');
        [$visible, $hidden] = $this->as($this->owner, function () use ($developer) {
            $visible = $this->create('VIS', 'standard');
            $hidden = $this->create('HID', 'standard');
            $this->addMember($visible, $developer, 'member');

            return [$visible, $hidden];
        });

        $this->as($developer, function () use ($developer, $visible, $hidden) {
            $this->assertFailsWith(ProjectOperationDenied::class, fn () => $this->update($developer, $visible, 0, ['name' => 'Mine']));
            $this->assertFailsWith(ProjectNotFound::class, fn () => $this->update($developer, $hidden, 0, ['name' => 'Mine']));
            $this->assertFailsWith(ProjectOperationDenied::class, fn () => $this->app->make(CreateProject::class)->handle($developer, 'DEV', 'Rogue'));
        });

        $this->as($this->owner, fn () => $this->assertSame(0, $this->app->make(ProjectDirectory::class)->find($this->owner, $visible)?->version));
    }

    public function test_project_manager_scope_and_optimistic_update(): void
    {
        $manager = $this->member('developer');
        [$project, $other] = $this->as($this->owner, function () use ($manager) {
            $project = $this->create('MGR', 'standard');
            $other = $this->create('OTH', 'standard');
            $this->addMember($project, $manager, 'manager');
            $this->addMember($other, $manager, 'member');

            return [$project, $other];
        });

        $this->as($manager, function () use ($manager, $project, $other) {
            $this->assertTrue(Gate::forUser($manager)->allows('projects.manage', [Scope::project($project)]));
            $this->assertFalse(Gate::forUser($manager)->allows('projects.manage', [Scope::project($other)]));
            $this->assertFalse(Gate::forUser($manager)->allows('projects.manage'));

            $version = $this->update($manager, $project, 0, ['name' => 'Managed', 'confidentiality' => 'restricted']);
            $this->assertSame(1, $version);
            $this->assertFailsWith(ProjectVersionConflict::class, fn () => $this->update($manager, $project, 0, ['name' => 'Stale']));
            $this->assertFailsWith(ProjectOperationDenied::class, fn () => $this->update($manager, $other, 0, ['name' => 'Not mine']));
            $this->assertFailsWith(\InvalidArgumentException::class, fn () => $this->update($manager, $project, 1, ['key' => 'NEW']));

            $row = DB::selectOne('select key, name, confidentiality, lock_version from portfolio.projects where id = ?', [$project]);
            $this->assertSame(['MGR', 'Managed', 'restricted', 1], [$row->key, $row->name, $row->confidentiality, (int) $row->lock_version]);

            $payload = json_decode((string) DB::selectOne("select payload::text as payload from events.domain_outbox where event_name = 'project.updated' and aggregate_id = ?", [$project])->payload, true);
            $this->assertEquals(['fields' => ['name', 'confidentiality'], 'confidentiality' => ['from' => 'standard', 'to' => 'restricted']], $payload);
            $this->assertStringNotContainsString('Managed', json_encode($payload, JSON_THROW_ON_ERROR));
        });
    }

    public function test_creation_makes_creator_manager_and_duplicate_key_is_generic(): void
    {
        $admin = $this->member('org_admin');

        $project = $this->as($this->owner, function () {
            $project = $this->create('ORBX', 'restricted');
            $this->assertSame(1, (int) DB::selectOne("select count(*) as total from portfolio.project_members where project_id = ? and role = 'manager'", [$project])->total);
            $this->assertSame(1, $this->events('project.created', $project));
            $this->assertFailsWith(\InvalidArgumentException::class, fn () => $this->create('orbx', 'standard'));

            return $project;
        });

        $this->as($admin, function () use ($admin) {
            try {
                $this->app->make(CreateProject::class)->handle($admin, 'ORBX', 'Duplicate');
                $this->fail('Expected ProjectKeyUnavailable.');
            } catch (ProjectKeyUnavailable $exception) {
                $this->assertSame('This project key cannot be used.', $exception->getMessage());
            }

            // The duplicate only rolled back its savepoint: the unit of work goes on.
            $this->assertIsString($this->app->make(CreateProject::class)->handle($admin, 'ORBY', 'Next'));
        });

        $this->assertNotSame('', $project);
    }

    public function test_restricted_detail_through_organization_role_is_audited(): void
    {
        $admin = $this->member('org_admin');
        $restricted = $this->as($this->owner, fn () => $this->create('SEC', 'restricted'));

        $this->as($admin, function () use ($admin, $restricted) {
            $directory = $this->app->make(ProjectDirectory::class);
            $directory->visible($admin);
            $this->assertSame(0, $this->events('audit.sensitive_access.granted', $restricted)); // listings are not audited

            $directory->find($admin, $restricted);
            $this->assertSame(1, $this->events('audit.sensitive_access.granted', $restricted));
        });

        // The owner created it and is its manager: a member's access is not "sensitive".
        $this->as($this->owner, function () use ($restricted) {
            $this->app->make(ProjectDirectory::class)->find($this->owner, $restricted);
            $this->assertSame(1, $this->events('audit.sensitive_access.granted', $restricted));
        });
    }

    public function test_revoking_organization_membership_closes_project_memberships(): void
    {
        $manager = $this->member('developer');
        $managerMembership = $this->as($this->owner, fn () => $this->membershipId($manager));

        $project = $this->as($this->owner, function () use ($manager) {
            $project = $this->create('REV', 'standard');
            $this->addMember($project, $manager, 'manager');
            $this->app->make(RevokeMembership::class)->handle($this->owner, $this->membershipId($manager));

            return $project;
        });

        $this->as($this->owner, function () use ($project, $managerMembership) {
            $row = DB::selectOne('select removed_reason, removed_at from portfolio.project_members where project_id = ? and membership_id = ?', [$project, $managerMembership]);
            $this->assertSame('membership_revoked', $row->removed_reason);
            $this->assertNotNull($row->removed_at);
        });
    }

    private function create(string $key, string $confidentiality): string
    {
        return $this->app->make(CreateProject::class)->handle($this->owner, $key, "Project {$key}", 'general', $confidentiality);
    }

    /**
     * @param  array{name?: string, description?: string|null, type?: string, status?: string, confidentiality?: string}  $changes
     */
    private function update(User $actor, string $projectId, int $version, array $changes): int
    {
        return $this->app->make(UpdateProject::class)->handle($actor, $projectId, $version, $changes);
    }

    private function link(string $projectId, string $teamId): void
    {
        DB::insert(
            'insert into portfolio.project_team_links (organization_id, project_id, team_id, linked_by_membership_id)
             values (platform.current_organization_id(), ?::uuid, ?::uuid, ?::uuid)',
            [$projectId, $teamId, $this->membershipId($this->owner)]
        );
    }

    private function addMember(string $projectId, User $user, string $role): void
    {
        DB::insert(
            'insert into portfolio.project_members (organization_id, project_id, membership_id, role, assigned_by_membership_id)
             values (platform.current_organization_id(), ?::uuid, ?::uuid, ?, ?::uuid)',
            [$projectId, $this->membershipId($user), $role, $this->membershipId($this->owner)]
        );
    }

    /**
     * @param  list<string>  $keys
     */
    private function assertSees(User $actor, array $keys): void
    {
        $this->as($actor, function () use ($actor, $keys) {
            $visible = array_map(fn (ProjectSummary $p) => $p->key, $this->app->make(ProjectDirectory::class)->visible($actor));
            $this->assertSame($keys, $visible);
        });
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
