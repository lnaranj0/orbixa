<?php

namespace Modules\Teams\Tests\Security;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Modules\Access\Contracts\Scope;
use Modules\IdentityTenancy\Application\AcceptInvitation;
use Modules\IdentityTenancy\Application\CreateOrganization;
use Modules\IdentityTenancy\Application\InviteMember;
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
use Modules\Teams\Contracts\TeamNotFound;
use Modules\Teams\Contracts\TeamOperationDenied;

/**
 * Spec "Líder fuera de su rama" (D14): structure A -> {B -> B1, C}. The
 * lead of B (a developer without organization-level teams.manage) manages B
 * and its subteams only.
 */
final class TeamLeadScopeTest extends SecurityTestCase
{
    private TenantContext $tenant;

    private User $owner;

    private User $lead;

    private OrganizationId $organization;

    /** @var array<string, string> */
    private array $teams = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->app->make(TenantContext::class);
        $this->owner = $this->user();
        $this->organization = $this->app->make(CreateOrganization::class)->handle($this->owner, 'Leads Inc');
        $this->lead = $this->member('developer');

        $this->as($this->owner, function () {
            $create = $this->app->make(CreateTeam::class);
            $this->teams['A'] = $create->handle($this->owner, 'A');
            $this->teams['B'] = $create->handle($this->owner, 'B', $this->teams['A']);
            $this->teams['B1'] = $create->handle($this->owner, 'B1', $this->teams['B']);
            $this->teams['C'] = $create->handle($this->owner, 'C', $this->teams['A']);
            $this->app->make(AssignTeamMember::class)->handle($this->owner, $this->teams['B'], $this->membershipId($this->lead), 'lead');
        });
    }

    public function test_scoped_permission_covers_own_team_and_descendants_only(): void
    {
        $this->as($this->lead, function () {
            $gate = Gate::forUser($this->lead);
            $this->assertFalse($gate->allows('teams.manage'), 'No organization-level permission.');
            $this->assertTrue($gate->allows('teams.manage', [Scope::team($this->teams['B'])]));
            $this->assertTrue($gate->allows('teams.manage', [Scope::team($this->teams['B1'])]));
            $this->assertFalse($gate->allows('teams.manage', [Scope::team($this->teams['A'])]), 'Never the parent.');
            $this->assertFalse($gate->allows('teams.manage', [Scope::team($this->teams['C'])]), 'Never a sibling.');
        });
    }

    public function test_lead_manages_inside_the_branch(): void
    {
        $member = $this->member('developer');

        $this->as($this->lead, function () use ($member) {
            $b2 = $this->app->make(CreateTeam::class)->handle($this->lead, 'B2', $this->teams['B']);
            $this->app->make(MoveTeam::class)->handle($this->lead, $this->teams['B1'], $b2);
            $this->app->make(AssignTeamMember::class)->handle($this->lead, $this->teams['B1'], $this->membershipId($member));
            $this->app->make(RemoveTeamMember::class)->handle($this->lead, $this->teams['B1'], $this->membershipId($member));
            $this->app->make(ArchiveTeam::class)->handle($this->lead, $this->teams['B1']);

            $this->assertSame('archived', DB::selectOne('select status from teams.teams where id = ?', [$this->teams['B1']])->status);
        });
    }

    public function test_lead_cannot_act_outside_the_branch_or_escalate(): void
    {
        $member = $this->member('developer');

        $this->as($this->lead, function () use ($member) {
            $create = $this->app->make(CreateTeam::class);
            $move = $this->app->make(MoveTeam::class);
            $assign = $this->app->make(AssignTeamMember::class);

            $this->assertFailsWith(TeamOperationDenied::class, fn () => $create->handle($this->lead, 'Root'));
            $this->assertViolation(fn () => $create->handle($this->lead, 'Under C', $this->teams['C']));
            $this->assertViolation(fn () => $create->handle($this->lead, 'Under A', $this->teams['A']));
            $this->assertFailsWith(TeamNotFound::class, fn () => $this->app->make(ArchiveTeam::class)->handle($this->lead, $this->teams['C']));
            $this->assertFailsWith(TeamNotFound::class, fn () => $assign->handle($this->lead, $this->teams['C'], $this->membershipId($member)));

            // Cannot take the team out of the branch, nor make it a root.
            $this->assertViolation(fn () => $move->handle($this->lead, $this->teams['B'], $this->teams['C']));
            $this->assertFailsWith(TeamOperationDenied::class, fn () => $move->handle($this->lead, $this->teams['B'], null));

            // Cannot appoint leads, add themselves, or act on the parent.
            $this->assertFailsWith(TeamOperationDenied::class, fn () => $assign->handle($this->lead, $this->teams['B1'], $this->membershipId($member), 'lead'));
            $this->assertFailsWith(TeamOperationDenied::class, fn () => $assign->handle($this->lead, $this->teams['B1'], $this->membershipId($this->lead)));
            $this->assertFailsWith(TeamNotFound::class, fn () => $move->handle($this->lead, $this->teams['A'], $this->teams['B']));
        });
    }

    public function test_removing_the_lead_role_removes_the_scope(): void
    {
        $this->as($this->owner, fn () => $this->app->make(RemoveTeamMember::class)->handle($this->owner, $this->teams['B'], $this->membershipId($this->lead)));

        $this->as($this->lead, function () {
            $this->assertFalse(Gate::forUser($this->lead)->allows('teams.manage', [Scope::team($this->teams['B1'])]));
            $this->assertFailsWith(TeamNotFound::class, fn () => $this->app->make(ArchiveTeam::class)->handle($this->lead, $this->teams['B1']));
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

    private function assertViolation(\Closure $operation): void
    {
        $this->assertFailsWith(HierarchyViolation::class, $operation);
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
