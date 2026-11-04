<?php

namespace Modules\Monitoring\Tests\Security;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\IdentityTenancy\Application\AcceptInvitation;
use Modules\IdentityTenancy\Application\CreateOrganization;
use Modules\IdentityTenancy\Application\InviteMember;
use Modules\IdentityTenancy\Infrastructure\Persistence\User;
use Modules\Monitoring\Application\IssueGrant;
use Modules\Platform\Contracts\Tenancy\OrganizationId;
use Modules\Platform\Contracts\Tenancy\TenantContext;
use Modules\Platform\Tests\Security\SecurityTestCase;
use Modules\Portfolio\Application\AddProjectMember;
use Modules\Portfolio\Application\CreateProject;
use Modules\Teams\Application\CreateTeam;
use Modules\Work\Application\AssignWorkItem;
use Modules\Work\Application\CreateWorkItem;
use Modules\Work\Application\LinkItemTeam;

/**
 * 04 increment 7: spec "Viewer intenta mutar tarea". Writes through Work on
 * an item a grant covers are 403 (audited); on anything else the usual 404.
 * Grants never open Work's own reads.
 */
final class ReadOnlyWritesTest extends SecurityTestCase
{
    private TenantContext $tenant;

    private User $owner;

    private OrganizationId $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->app->make(TenantContext::class);
        $this->owner = $this->user();
        $this->organization = $this->app->make(CreateOrganization::class)->handle($this->owner, 'Read Only Inc');
    }

    public function test_covered_items_are_403_uncovered_404_and_nothing_changes(): void
    {
        $observed = $this->member('developer');
        $world = $this->world($observed);
        $viewer = $this->viewerWith('user', ['target_membership_id' => $world['observed']]);
        $plain = $this->member('viewer');
        $this->actingAs($viewer);

        $covered = $world['s1'];
        $writes = fn (string $item) => [
            $this->patchJson($this->url("items/{$item}"), ['expected_version' => 0, 'title' => 'Hijacked']),
            $this->postJson($this->url("items/{$item}/transitions"), ['expected_version' => 0, 'to' => 'ready']),
            $this->postJson($this->url("items/{$item}/assignments"), ['membership_id' => $world['observed']]),
            $this->postJson($this->url("items/{$item}/labels"), ['label_id' => (string) Str::uuid()]),
            $this->postJson($this->url("items/{$item}/teams"), ['team_id' => $world['team']]),
            $this->postJson($this->url("items/{$item}/dependencies"), ['blocker_item_id' => $world['s4']]),
            $this->deleteJson($this->url("items/{$item}/assignments/{$world['observed']}")),
        ];

        foreach ($writes($covered) as $response) {
            $response->assertForbidden();
        }
        foreach ($writes($world['s4']) as $response) {
            $response->assertNotFound(); // not covered by the grant
        }
        // Work's own reads stay closed: the grant is not project visibility.
        $this->getJson($this->url("items/{$covered}"))->assertNotFound();
        // Without any grant, the same item is just not found.
        $this->actingAs($plain)->patchJson($this->url("items/{$covered}"), ['expected_version' => 0, 'title' => 'Nope'])->assertNotFound();

        $this->as($this->owner, function () use ($covered) {
            $row = DB::selectOne('select title, status, lock_version from work.items where id = ?', [$covered]);
            $this->assertSame(['Item SP-1', 'backlog', 0], [$row->title, $row->status, (int) $row->lock_version]);
            $this->assertSame(1, (int) DB::selectOne("select count(*) as total from events.domain_outbox where event_name = 'monitoring.viewer.access_denied' and aggregate_id = ?", [$covered])->total);
        });
    }

    private function url(string $path): string
    {
        return "/api/v1/organizations/{$this->organization->value}/{$path}";
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
