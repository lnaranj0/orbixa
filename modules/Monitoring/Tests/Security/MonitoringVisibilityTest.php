<?php

namespace Modules\Monitoring\Tests\Security;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\IdentityTenancy\Application\AcceptInvitation;
use Modules\IdentityTenancy\Application\CreateOrganization;
use Modules\IdentityTenancy\Application\InviteMember;
use Modules\IdentityTenancy\Application\RevokeMembership;
use Modules\IdentityTenancy\Infrastructure\Persistence\User;
use Modules\Monitoring\Application\ChangeGrant;
use Modules\Monitoring\Application\IssueGrant;
use Modules\Monitoring\Application\MonitoringQueries;
use Modules\Monitoring\Contracts\MonitoringNotFound;
use Modules\Platform\Contracts\Tenancy\OrganizationId;
use Modules\Platform\Contracts\Tenancy\TenantContext;
use Modules\Platform\Tests\Security\SecurityTestCase;
use Modules\Portfolio\Application\AddProjectMember;
use Modules\Portfolio\Application\CreateProject;
use Modules\Teams\Application\CreateTeam;
use Modules\Teams\Application\MoveTeam;
use Modules\Work\Application\AssignWorkItem;
use Modules\Work\Application\CreateWorkItem;
use Modules\Work\Application\LinkItemTeam;
use Modules\Work\Contracts\Monitoring\MonitoredItem;

/**
 * 04 increment 6: the monitoring visibility matrix (spec "Visor de una
 * persona", "de un equipo", "Inclusión opcional de subequipos", "Visor de
 * todos", "Viewer sin grant", "Revocación inmediata", "Prevención de fuga
 * por agregados").
 */
final class MonitoringVisibilityTest extends SecurityTestCase
{
    private TenantContext $tenant;

    private User $owner;

    private OrganizationId $organization;

    /** @var array<string, string> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->app->make(TenantContext::class);
        $this->owner = $this->user();
        $this->organization = $this->app->make(CreateOrganization::class)->handle($this->owner, 'Monitoring Inc');
    }

    public function test_visibility_matrix(): void
    {
        $observed = $this->member('developer');
        $world = $this->world($observed);
        $grant = fn (string $scope, array $extra = []) => $this->viewerWith($scope, $extra);

        $none = $this->member('viewer');
        $user = $grant('user', ['target_membership_id' => $world['observed']]);
        $userRestricted = $grant('user', ['target_membership_id' => $world['observed'], 'visibility_level' => 'restricted']);
        $team = $grant('team', ['target_team_id' => $world['team']]);
        $teamTree = $grant('team', ['target_team_id' => $world['team'], 'include_descendants' => true]);
        $organization = $grant('organization');
        $organizationRestricted = $grant('organization', ['visibility_level' => 'restricted']);

        $this->assertSees($none, []);
        $this->assertSees($user, ['SP-1']);
        $this->assertSees($userRestricted, ['RP-1', 'SP-1']);
        $this->assertSees($team, ['SP-2']);
        $this->assertSees($teamTree, ['SP-2', 'SP-3']);
        $this->assertSees($organization, ['SP-1', 'SP-2', 'SP-3', 'SP-4']);
        $this->assertSees($organizationRestricted, ['RP-1', 'RP-2', 'SP-1', 'SP-2', 'SP-3', 'SP-4']);
        $this->assertSees($this->owner, ['RP-1', 'RP-2', 'SP-1', 'SP-2', 'SP-3', 'SP-4']); // implicit owner/admin scope

        // Viewer without grants: zero data and 404 on any item.
        $this->as($none, function () use ($none, $world) {
            $queries = $this->app->make(MonitoringQueries::class);
            $scope = $queries->scope($none);
            $this->assertSame(0, $queries->overview($none, $scope)['total']);
            $this->assertFailsWith(MonitoringNotFound::class, fn () => $queries->item($none, $scope, $world['s1']));
        });

        // Per-member and per-team views only narrow, and 404 outside the grants.
        $this->as($user, function () use ($user, $world) {
            $queries = $this->app->make(MonitoringQueries::class);
            $scope = $queries->scope($user);
            $this->assertSame($world['observed'], $queries->requireMember($scope, $world['observed']));
            $this->assertFailsWith(MonitoringNotFound::class, fn () => $queries->requireMember($scope, $world['other']));
            $this->assertFailsWith(MonitoringNotFound::class, fn () => $queries->requireTeam($scope, $world['team']));
            $this->assertSame('About SP-1', $queries->item($user, $scope, $world['s1'])->description);
            $this->assertFailsWith(MonitoringNotFound::class, fn () => $queries->item($user, $scope, $world['s4']));
            $this->assertFailsWith(MonitoringNotFound::class, fn () => $queries->item($user, $scope, $world['r1']));
        });
        $this->as($organization, function () use ($organization, $world) {
            $queries = $this->app->make(MonitoringQueries::class);
            $scope = $queries->scope($organization);
            $counts = $queries->overview($organization, $scope, ['team_ids' => [$queries->requireTeam($scope, $world['team'])]]);
            $this->assertSame(1, $counts['total'], 'Counts only inside scope and narrowing.');
            $this->assertSame(4, $queries->overview($organization, $scope)['total']);
        });
    }

    public function test_changes_apply_on_the_next_read(): void
    {
        $observed = $this->member('developer');
        $world = $this->world($observed);
        $byUser = $this->viewerWith('user', ['target_membership_id' => $world['observed']]);
        $byTree = $this->viewerWith('team', ['target_team_id' => $world['team'], 'include_descendants' => true]);
        $future = $this->viewerWith('organization', ['starts_at' => (new \DateTimeImmutable('+2 days'))->format(DATE_ATOM)]);

        $this->assertSees($future, [], 'Not valid before starts_at.');

        // Moving the subteam out of the tree narrows the team grant immediately.
        $this->assertSees($byTree, ['SP-2', 'SP-3']);
        $this->as($this->owner, fn () => $this->app->make(MoveTeam::class)->handle($this->owner, $world['subteam'], null));
        $this->assertSees($byTree, ['SP-2']);

        // Revoking the grant, then the observed member's membership.
        $grant = (string) $this->as($this->owner, fn () => DB::selectOne("select id::text as id from monitoring.grants where scope_type = 'team'")->id);
        $this->as($this->owner, fn () => $this->app->make(ChangeGrant::class)->revoke($this->owner, $grant));
        $this->assertSees($byTree, []);

        $this->assertSees($byUser, ['SP-1']);
        $this->as($this->owner, fn () => $this->app->make(RevokeMembership::class)->handle($this->owner, $world['observed']));
        $this->assertSees($byUser, []);
    }

    public function test_restricted_and_organization_reads_are_audited_once_per_window(): void
    {
        $observed = $this->member('developer');
        $world = $this->world($observed);
        $organization = $this->viewerWith('organization');
        $user = $this->viewerWith('user', ['target_membership_id' => $world['observed']]);

        $this->assertSees($organization, ['SP-1', 'SP-2', 'SP-3', 'SP-4']);
        $this->assertSees($organization, ['SP-1', 'SP-2', 'SP-3', 'SP-4']);
        $this->assertSees($user, ['SP-1']);

        $this->as($this->owner, function () {
            $audits = DB::select("select aggregate_id::text as grant_id from events.domain_outbox where event_name = 'audit.sensitive_access.granted' and payload->>'operation' = 'monitoring.view'");
            $this->assertCount(1, $audits);
            $this->assertSame('organization', DB::selectOne('select scope_type from monitoring.grants where id = ?', [$audits[0]->grant_id])->scope_type);
        });
    }

    public function test_other_organizations_stay_invisible(): void
    {
        $observed = $this->member('developer');
        $world = $this->world($observed);

        $stranger = $this->user();
        $other = $this->app->make(CreateOrganization::class)->handle($stranger, 'Other Inc');
        $this->tenant->runFor($stranger, $other, function () use ($stranger, $world) {
            $queries = $this->app->make(MonitoringQueries::class);
            $scope = $queries->scope($stranger);
            $this->assertSame([], $queries->items($stranger, $scope, [], null, 50));
            $this->assertFailsWith(MonitoringNotFound::class, fn () => $queries->item($stranger, $scope, $world['s1']));
        });
    }

    /**
     * S (standard) and R (restricted) projects; team T with subteam T2.
     * SP-1 and RP-1 are assigned to the observed member; SP-2 and RP-2 linked
     * to T; SP-3 linked to T2; SP-4 unrelated.
     *
     * @return array<string, string>
     */
    private function world(User $observed): array
    {
        return $this->as($this->owner, function () use ($observed) {
            $observedId = $this->membershipId($observed);
            $other = $this->membershipId($this->owner);
            $standard = $this->app->make(CreateProject::class)->handle($this->owner, 'SP', 'Standard');
            $restricted = $this->app->make(CreateProject::class)->handle($this->owner, 'RP', 'Restricted', 'general', 'restricted');
            foreach ([$standard, $restricted] as $project) {
                $this->app->make(AddProjectMember::class)->handle($this->owner, $project, $observedId);
            }
            $team = $this->app->make(CreateTeam::class)->handle($this->owner, 'T');
            $subteam = $this->app->make(CreateTeam::class)->handle($this->owner, 'T2', $team);
            $create = fn (string $project, string $key) => $this->app->make(CreateWorkItem::class)->handle($this->owner, $project, ['type' => 'task', 'title' => "Item {$key}", 'description' => "About {$key}"]);

            $ids = [
                's1' => $create($standard, 'SP-1'), 's2' => $create($standard, 'SP-2'), 's3' => $create($standard, 'SP-3'),
                's4' => $create($standard, 'SP-4'), 'r1' => $create($restricted, 'RP-1'), 'r2' => $create($restricted, 'RP-2'),
            ];
            $this->app->make(AssignWorkItem::class)->assign($this->owner, $ids['s1'], $observedId, 'owner');
            $this->app->make(AssignWorkItem::class)->assign($this->owner, $ids['r1'], $observedId, 'owner');
            $this->app->make(LinkItemTeam::class)->link($this->owner, $ids['s2'], $team);
            $this->app->make(LinkItemTeam::class)->link($this->owner, $ids['r2'], $team);
            $this->app->make(LinkItemTeam::class)->link($this->owner, $ids['s3'], $subteam);

            return $ids + ['observed' => $observedId, 'other' => $other, 'team' => $team, 'subteam' => $subteam];
        });
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function viewerWith(string $scope, array $extra = []): User
    {
        $viewer = $this->member('viewer');
        $this->as($this->owner, function () use ($viewer, $scope, $extra) {
            $result = $this->app->make(IssueGrant::class)->handle($this->owner, $extra + [
                'viewer_membership_id' => $this->membershipId($viewer),
                'scope_type' => $scope,
                'reason' => 'Follow-up',
                'expires_at' => (new \DateTimeImmutable('+30 days'))->format(DATE_ATOM),
            ]);
            $this->assertFalse($result->wasRejected());
        });

        return $viewer;
    }

    /**
     * @param  list<string>  $keys
     */
    private function assertSees(User $viewer, array $keys, string $message = ''): void
    {
        $this->as($viewer, function () use ($viewer, $keys, $message) {
            $queries = $this->app->make(MonitoringQueries::class);
            $scope = $queries->scope($viewer);
            $seen = array_map(fn (MonitoredItem $i) => $i->key, $queries->items($viewer, $scope, [], null, 100));
            sort($seen);
            $this->assertSame($keys, $seen, $message);
            $this->assertSame(count($keys), $queries->overview($viewer, $scope)['total'], 'Counts match the listing.');
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
