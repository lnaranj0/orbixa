<?php

namespace Modules\Portfolio\Tests\Security;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Modules\Access\Contracts\Scope;
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
use Modules\Portfolio\Application\LinkProjectTeam;
use Modules\Portfolio\Application\RemoveProjectMember;
use Modules\Portfolio\Application\UnlinkProjectTeam;
use Modules\Portfolio\Application\UpdateProject;
use Modules\Portfolio\Contracts\ProjectLinkNotFound;
use Modules\Portfolio\Contracts\ProjectMemberNotFound;
use Modules\Portfolio\Contracts\ProjectNotFound;
use Modules\Portfolio\Contracts\ProjectOperationDenied;
use Modules\Portfolio\Contracts\ProjectRuleViolation;
use Modules\Portfolio\Contracts\ProjectVisibility;
use Modules\Teams\Application\ArchiveTeam;
use Modules\Teams\Application\AssignTeamMember;
use Modules\Teams\Application\CreateTeam;

/**
 * 02 increment 6b: project members and team links (D2, D6, D13, D16) on
 * real PostgreSQL as the runtime role.
 */
final class ProjectMembershipTest extends SecurityTestCase
{
    private TenantContext $tenant;

    private User $owner;

    private OrganizationId $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->app->make(TenantContext::class);
        $this->owner = $this->user();
        $this->organization = $this->app->make(CreateOrganization::class)->handle($this->owner, 'Members Inc');
    }

    public function test_member_rules_and_history(): void
    {
        $manager = $this->member('developer');
        $developer = $this->member('developer');
        $other = $this->member('developer');

        $project = $this->as($this->owner, function () use ($manager) {
            $project = $this->project('MEM');
            $this->add($this->owner, $project, $manager, 'manager');

            $this->assertFailsWith(ProjectOperationDenied::class, fn () => $this->app->make(AddProjectMember::class)->handle($this->owner, $project, $this->membershipId($this->owner)));
            $this->assertFailsWith(MembershipReferenceInvalid::class, fn () => $this->app->make(AddProjectMember::class)->handle($this->owner, $project, (string) Str::uuid()));
            $this->assertFailsWith(MembershipReferenceInvalid::class, fn () => $this->app->make(AddProjectMember::class)->handle($this->owner, $project, 'not-a-uuid'));

            return $project;
        });

        // The project manager manages members but never other managers (D6).
        $this->as($manager, function () use ($manager, $developer, $other, $project) {
            $this->add($manager, $project, $developer);
            $this->add($manager, $project, $developer); // idempotent
            $this->assertFailsWith(ProjectOperationDenied::class, fn () => $this->add($manager, $project, $other, 'manager'));
            $this->assertFailsWith(ProjectOperationDenied::class, fn () => $this->app->make(RemoveProjectMember::class)->handle($manager, $project, $this->membershipId($manager)));
            $this->assertFailsWith(ProjectOperationDenied::class, fn () => $this->app->make(RemoveProjectMember::class)->handle($manager, $project, $this->membershipId($this->owner)));

            $this->app->make(RemoveProjectMember::class)->handle($manager, $project, $this->membershipId($developer));
            $this->assertFailsWith(ProjectMemberNotFound::class, fn () => $this->app->make(RemoveProjectMember::class)->handle($manager, $project, $this->membershipId($developer)));
        });

        // A plain member sees the project but cannot manage its members (403).
        $this->as($this->owner, fn () => $this->add($this->owner, $project, $other));
        $this->as($other, fn () => $this->assertFailsWith(ProjectOperationDenied::class, fn () => $this->add($other, $project, $developer)));

        $this->as($this->owner, function () use ($project, $developer) {
            $rows = DB::select(
                'select assigned_by_membership_id::text as by, assigned_at, removed_by_membership_id::text as removed_by, removed_at, removed_reason
                   from portfolio.project_members where project_id = ? and membership_id = ?',
                [$project, $this->membershipId($developer)]
            );
            $this->assertCount(1, $rows);
            $this->assertNotNull($rows[0]->assigned_at);
            $this->assertNotNull($rows[0]->removed_at);
            $this->assertSame('removed', $rows[0]->removed_reason);
            $this->assertSame($rows[0]->by, $rows[0]->removed_by);

            $this->assertSame(3, $this->events('project.member.added', $project)); // manager, developer, other (the creator comes with project.created)
            $this->assertSame(1, $this->events('project.member.removed', $project));
            $payloads = DB::selectOne("select string_agg(payload::text, ' ') as all from events.domain_outbox where aggregate_id = ?", [$project])->all;
            $this->assertStringNotContainsString('@', (string) $payloads);
        });
    }

    public function test_role_change_keeps_history_and_moves_the_scope(): void
    {
        $developer = $this->member('developer');
        $project = $this->as($this->owner, fn () => $this->project('ROL'));
        $developerMembership = $this->as($this->owner, fn () => $this->membershipId($developer));

        $this->as($this->owner, fn () => $this->add($this->owner, $project, $developer));
        $this->as($developer, fn () => $this->assertFalse(Gate::forUser($developer)->allows('projects.manage', [Scope::project($project)])));

        $this->as($this->owner, fn () => $this->add($this->owner, $project, $developer, 'manager'));
        $this->as($developer, fn () => $this->assertTrue(Gate::forUser($developer)->allows('projects.manage', [Scope::project($project)])));

        $this->as($this->owner, function () use ($project, $developerMembership) {
            $roles = DB::select('select role, removed_reason from portfolio.project_members where project_id = ? and membership_id = ? order by assigned_at, id', [$project, $developerMembership]);
            $this->assertSame([['member', 'removed'], ['manager', null]], array_map(fn ($r) => [$r->role, $r->removed_reason], $roles));
        });
    }

    public function test_archived_project_rejects_members_and_links(): void
    {
        $developer = $this->member('developer');
        $this->as($this->owner, function () use ($developer) {
            $project = $this->project('ARC');
            $team = $this->app->make(CreateTeam::class)->handle($this->owner, 'Team');
            $this->app->make(UpdateProject::class)->handle($this->owner, $project, 0, ['status' => 'archived']);

            $this->assertRule(ProjectRuleViolation::ARCHIVED, fn () => $this->add($this->owner, $project, $developer));
            $this->assertRule(ProjectRuleViolation::ARCHIVED, fn () => $this->app->make(LinkProjectTeam::class)->handle($this->owner, $project, $team));
        });
    }

    public function test_linking_requires_managing_project_and_team(): void
    {
        $lead = $this->member('developer');      // lead of Squad, no project management
        $manager = $this->member('developer');   // manager of the project, plain member of Squad
        $squadMember = $this->member('developer');

        [$project, $squad, $other, $archived] = $this->as($this->owner, function () use ($lead, $manager, $squadMember) {
            $squad = $this->app->make(CreateTeam::class)->handle($this->owner, 'Squad');
            $other = $this->app->make(CreateTeam::class)->handle($this->owner, 'Other');
            $archived = $this->app->make(CreateTeam::class)->handle($this->owner, 'Old');
            $this->app->make(ArchiveTeam::class)->handle($this->owner, $archived);
            $assign = $this->app->make(AssignTeamMember::class);
            $assign->handle($this->owner, $squad, $this->membershipId($lead), 'lead');
            $assign->handle($this->owner, $squad, $this->membershipId($manager));
            $assign->handle($this->owner, $squad, $this->membershipId($squadMember));

            $project = $this->project('LNK');
            $this->add($this->owner, $project, $manager, 'manager');

            return [$project, $squad, $other, $archived];
        });

        $foreignTeam = $this->foreignTeam();

        // A lead cannot pull a project into their team (spec "Enlace para heredar acceso").
        $this->as($lead, fn () => $this->assertFailsWith(ProjectNotFound::class, fn () => $this->app->make(LinkProjectTeam::class)->handle($lead, $project, $squad)));

        $this->as($manager, function () use ($manager, $project, $squad, $other, $archived, $foreignTeam) {
            $link = $this->app->make(LinkProjectTeam::class);
            $this->assertFailsWith(ProjectOperationDenied::class, fn () => $link->handle($manager, $project, $squad)); // member, not lead
            foreach ([$other, $archived, $foreignTeam, (string) Str::uuid(), 'not-a-uuid'] as $team) {
                $this->assertRule(ProjectRuleViolation::TEAM, fn () => $link->handle($manager, $project, $team));
            }
        });

        $this->as($squadMember, fn () => $this->assertFalse($this->app->make(ProjectVisibility::class)->canView($squadMember, $project)));

        // Owner manages both: link, idempotent, visible to the team, then unlink.
        $this->as($this->owner, function () use ($project, $squad) {
            $this->app->make(LinkProjectTeam::class)->handle($this->owner, $project, $squad);
            $this->app->make(LinkProjectTeam::class)->handle($this->owner, $project, $squad);
            $this->assertSame(1, $this->events('project.team.linked', $project));
        });
        $this->as($squadMember, fn () => $this->assertTrue($this->app->make(ProjectVisibility::class)->canView($squadMember, $project)));

        $this->as($manager, function () use ($manager, $project, $squad) {
            $this->app->make(UnlinkProjectTeam::class)->handle($manager, $project, $squad);
            $this->assertFailsWith(ProjectLinkNotFound::class, fn () => $this->app->make(UnlinkProjectTeam::class)->handle($manager, $project, $squad));
            $this->assertSame(1, $this->events('project.team.unlinked', $project));
        });
        $this->as($squadMember, fn () => $this->assertFalse($this->app->make(ProjectVisibility::class)->canView($squadMember, $project)));
    }

    public function test_manager_who_leads_the_team_links_a_restricted_project(): void
    {
        $managerLead = $this->member('developer');
        $squadMember = $this->member('developer');

        [$project, $squad] = $this->as($this->owner, function () use ($managerLead, $squadMember) {
            $squad = $this->app->make(CreateTeam::class)->handle($this->owner, 'Squad');
            $this->app->make(AssignTeamMember::class)->handle($this->owner, $squad, $this->membershipId($managerLead), 'lead');
            $this->app->make(AssignTeamMember::class)->handle($this->owner, $squad, $this->membershipId($squadMember));
            $project = $this->app->make(CreateProject::class)->handle($this->owner, 'SEC', 'Secret', 'general', 'restricted');
            $this->add($this->owner, $project, $managerLead, 'manager');

            return [$project, $squad];
        });

        $this->as($managerLead, fn () => $this->app->make(LinkProjectTeam::class)->handle($managerLead, $project, $squad));
        // Linked, but restricted still needs a direct membership.
        $this->as($squadMember, fn () => $this->assertFalse($this->app->make(ProjectVisibility::class)->canView($squadMember, $project)));
    }

    private function project(string $key): string
    {
        return $this->app->make(CreateProject::class)->handle($this->owner, $key, "Project {$key}");
    }

    private function add(User $actor, string $projectId, User $user, string $role = 'member'): void
    {
        $this->app->make(AddProjectMember::class)->handle($actor, $projectId, $this->membershipId($user), $role);
    }

    private function foreignTeam(): string
    {
        $stranger = $this->user();
        $other = $this->app->make(CreateOrganization::class)->handle($stranger, 'Foreign Inc');

        return $this->tenant->runFor($stranger, $other, fn () => $this->app->make(CreateTeam::class)->handle($stranger, 'Foreign'));
    }

    private function assertRule(string $rule, \Closure $operation): void
    {
        try {
            DB::transaction($operation);
            $this->fail('Expected ProjectRuleViolation.');
        } catch (ProjectRuleViolation $violation) {
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
