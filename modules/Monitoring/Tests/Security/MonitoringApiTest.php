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
 * 04 increment 8: monitoring API and screens end to end over HTTP.
 */
final class MonitoringApiTest extends SecurityTestCase
{
    private TenantContext $tenant;

    private User $owner;

    private OrganizationId $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->tenant = $this->app->make(TenantContext::class);
        $this->owner = $this->user();
        $this->organization = $this->app->make(CreateOrganization::class)->handle($this->owner, 'Monitoring Api');
    }

    public function test_viewer_without_grant_gets_zero_data(): void
    {
        $observed = $this->member('developer');
        $world = $this->world($observed);
        $viewer = $this->member('viewer');
        $this->actingAs($viewer);

        $this->getJson($this->url('overview'))->assertOk()->assertJsonPath('data.total', 0);
        $this->assertSame([], $this->getJson($this->url('items'))->assertOk()->json('data'));
        $this->getJson($this->url("items/{$world['s1']}"))->assertNotFound();
        $this->getJson($this->url("users/{$world['observed']}"))->assertNotFound();
        $this->getJson($this->url("teams/{$world['team']}"))->assertNotFound();
        $this->getJson($this->url('grants'))->assertForbidden();
        $this->postJson($this->url('grants'), ['viewer_membership_id' => $world['observed'], 'scope_type' => 'organization', 'reason' => 'x', 'expires_at' => $this->expires()])->assertForbidden();
        $this->getJson($this->url('export'))->assertNotFound(); // no export in 04
        $this->patchJson($this->url('items'), [])->assertStatus(405);
        $this->get("/o/{$this->organization->value}/monitoring")->assertOk()->assertSee('No hay trabajo en tu alcance');
        $this->get("/o/{$this->organization->value}/monitoring/grants")->assertForbidden();
    }

    public function test_scoped_views_pagination_and_cursor_invalidation(): void
    {
        $observed = $this->member('developer');
        $world = $this->world($observed);
        $byUser = $this->viewerWith('user', ['target_membership_id' => $world['observed']]);
        $everything = $this->viewerWith('organization');

        $this->actingAs($byUser);
        $this->assertSame(['SP-1'], array_column($this->getJson($this->url('items'))->json('data'), 'key'));
        $member = $this->getJson($this->url("users/{$world['observed']}"))->assertOk();
        $this->assertSame(['SP-1'], array_column($member->json('data'), 'key'));
        $this->assertSame(1, $member->json('counts.total'));
        $this->getJson($this->url("users/{$world['other']}"))->assertNotFound();
        $this->getJson($this->url("teams/{$world['team']}"))->assertNotFound();
        $this->getJson($this->url("items/{$world['s1']}"))->assertOk()->assertJsonPath('data.description', 'About SP-1');
        $this->get("/o/{$this->organization->value}/monitoring")->assertOk()->assertSee('Item SP-1')->assertDontSee('Item SP-4');

        $this->actingAs($everything);
        $first = $this->getJson($this->url('items?limit=2'))->assertOk();
        $this->assertCount(2, $first->json('data'));
        $this->assertArrayNotHasKey('total', $first->json());
        $cursor = urlencode((string) $first->json('next_cursor'));
        $this->assertCount(2, $this->getJson($this->url("items?limit=2&cursor={$cursor}"))->assertOk()->json('data'));
        $this->assertSame(1, $this->getJson($this->url("teams/{$world['team']}"))->assertOk()->json('counts.total'));

        // Revoking the grant changes the scope: the old cursor is rejected and the list empties.
        $grant = (string) $this->as($this->owner, fn () => DB::selectOne("select id::text as id from monitoring.grants where scope_type = 'organization'")->id);
        $this->actingAs($this->owner)->postJson($this->url("grants/{$grant}/revoke"), ['reason' => 'Done'])->assertNoContent();
        $this->actingAs($everything)->getJson($this->url("items?limit=2&cursor={$cursor}"))->assertStatus(422);
        $this->assertSame([], $this->getJson($this->url('items'))->json('data'));
    }

    public function test_grant_administration_over_http(): void
    {
        $observed = $this->member('developer');
        $world = $this->world($observed);
        $viewer = $this->member('viewer');
        $viewerId = $this->as($this->owner, fn () => $this->membershipId($viewer));
        $stranger = $this->user();
        $other = $this->app->make(CreateOrganization::class)->handle($stranger, 'Other Inc');
        $foreignTeam = $this->tenant->runFor($stranger, $other, fn () => $this->app->make(CreateTeam::class)->handle($stranger, 'Foreign'));
        $this->actingAs($this->owner);

        $created = $this->postJson($this->url('grants'), [
            'viewer_membership_id' => $viewerId, 'scope_type' => 'team', 'target_team_id' => $world['team'],
            'include_descendants' => true, 'reason' => 'Sprint review', 'expires_at' => $this->expires(),
            'organization_id' => (string) Str::uuid(), 'granted_by_membership_id' => $viewerId, // ignored (mass assignment)
        ])->assertCreated();
        $grant = (string) $created->json('data.id');
        $this->assertSame($this->as($this->owner, fn () => $this->membershipId($this->owner)), $created->json('data.granted_by_membership_id'));

        $foreign = $this->postJson($this->url('grants'), ['viewer_membership_id' => $viewerId, 'scope_type' => 'team', 'target_team_id' => $foreignTeam, 'reason' => 'x', 'expires_at' => $this->expires()])->assertStatus(422);
        $unknown = $this->postJson($this->url('grants'), ['viewer_membership_id' => $viewerId, 'scope_type' => 'team', 'target_team_id' => (string) Str::uuid(), 'reason' => 'x', 'expires_at' => $this->expires()])->assertStatus(422);
        $this->assertSame($unknown->json('message'), $foreign->json('message'));
        $this->assertStringNotContainsString('Foreign', (string) $foreign->getContent());
        $this->as($this->owner, fn () => $this->assertSame(2, (int) DB::selectOne("select count(*) as total from monitoring.grant_events where type = 'rejected'")->total));

        $this->patchJson($this->url("grants/{$grant}"), ['expected_version' => 0, 'reason' => 'Quarterly'])->assertOk()->assertJsonPath('data.version', 1);
        $this->patchJson($this->url("grants/{$grant}"), ['expected_version' => 0, 'reason' => 'Stale'])->assertStatus(409);
        $this->assertCount(1, $this->getJson($this->url('grants'))->assertOk()->json('data'));
        $this->get("/o/{$this->organization->value}/monitoring/grants")->assertOk()->assertSee('Quarterly');

        $this->post("/o/{$this->organization->value}/monitoring/grants/{$grant}/revoke")->assertRedirect();
        $this->postJson($this->url("grants/{$grant}/revoke"))->assertNotFound();
        $this->assertSame([], $this->getJson($this->url('grants'))->json('data'));
    }

    private function url(string $path): string
    {
        return "/api/v1/organizations/{$this->organization->value}/monitoring/{$path}";
    }

    private function expires(): string
    {
        return (new \DateTimeImmutable('+30 days'))->format(DATE_ATOM);
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
