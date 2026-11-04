<?php

namespace Modules\Portfolio\Tests\Security;

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
use Modules\Teams\Application\AssignTeamMember;
use Modules\Teams\Application\CreateTeam;
use Modules\Teams\Application\RemoveTeamMember;

/**
 * 02 increment 7a: JSON API end to end over HTTP on real PostgreSQL (D10,
 * D11, D15): keyset pages without totals, filters after visibility, signed
 * cursors, 404/403/409/422, Idempotency-Key and throttling.
 */
final class PortfolioApiTest extends SecurityTestCase
{
    private TenantContext $tenant;

    private User $owner;

    private OrganizationId $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->app->make(TenantContext::class);
        $this->owner = $this->user();
        $this->organization = $this->app->make(CreateOrganization::class)->handle($this->owner, 'Api Inc');
    }

    public function test_pages_filters_and_cursors_only_cover_visible_projects(): void
    {
        $developer = $this->member('developer');
        $this->as($this->owner, function () use ($developer) {
            foreach (['AAA' => 'standard', 'BBB' => 'restricted', 'CCC' => 'standard', 'DDD' => 'restricted', 'EEE' => 'standard'] as $key => $confidentiality) {
                $id = $this->app->make(CreateProject::class)->handle($this->owner, $key, "Project {$key}", 'general', $confidentiality);
                if (in_array($key, ['AAA', 'BBB', 'EEE'], true)) {
                    $this->app->make(AddProjectMember::class)->handle($this->owner, $id, $this->membershipId($developer));
                }
            }
        });

        $first = $this->actingAs($developer)->getJson($this->url('projects?limit=2'))->assertOk();
        $this->assertSame(['AAA', 'BBB'], array_column($first->json('data'), 'key'));
        $this->assertArrayNotHasKey('total', $first->json());
        $cursor = (string) $first->json('next_cursor');

        $second = $this->getJson($this->url('projects?limit=2&cursor='.urlencode($cursor)))->assertOk();
        $this->assertSame(['EEE'], array_column($second->json('data'), 'key'));
        $this->assertNull($second->json('next_cursor'));

        $restricted = $this->getJson($this->url('projects?confidentiality=restricted'))->assertOk();
        $this->assertSame(['BBB'], array_column($restricted->json('data'), 'key'));

        // Edited cursor, or a cursor reused with other filters: rejected.
        [$payload, $signature] = explode('.', $cursor);
        $forged = rtrim(strtr(base64_encode((string) json_encode(['r' => 'x', 'p' => ['A', (string) Str::uuid()]])), '+/', '-_'), '=').'.'.$signature;
        $this->getJson($this->url('projects?cursor='.urlencode($forged)))->assertStatus(422);
        $this->getJson($this->url('projects?limit=2&status=active&cursor='.urlencode($cursor)))->assertStatus(422);
        $this->getJson($this->url('projects?confidentiality=secret'))->assertStatus(422);

        // The owner sees all five.
        $this->assertCount(5, $this->actingAs($this->owner)->getJson($this->url('projects'))->json('data'));
    }

    public function test_foreign_unknown_and_hidden_projects_are_the_same_404(): void
    {
        $developer = $this->member('developer');
        $hidden = $this->as($this->owner, fn () => $this->app->make(CreateProject::class)->handle($this->owner, 'HID', 'Hidden'));

        $stranger = $this->user();
        $other = $this->app->make(CreateOrganization::class)->handle($stranger, 'Other Inc');
        $foreign = $this->tenant->runFor($stranger, $other, fn () => $this->app->make(CreateProject::class)->handle($stranger, 'FOR', 'Foreign'));

        $responses = [
            $this->actingAs($developer)->getJson($this->url("projects/{$hidden}")),
            $this->getJson($this->url("projects/{$foreign}")),
            $this->getJson($this->url('projects/'.Str::uuid())),
            $this->getJson($this->url('projects/not-a-uuid')),
        ];
        foreach ($responses as $response) {
            $response->assertNotFound();
            $this->assertSame($responses[0]->json('message'), $response->json('message'));
        }

        // Another organization's id in the path: 404 from the organization middleware.
        $this->actingAs($developer)->getJson("/api/v1/organizations/{$other->value}/projects/{$foreign}")->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->getJson($this->url('projects'))->assertUnauthorized();
    }

    public function test_patch_is_403_without_manage_and_409_when_stale(): void
    {
        $developer = $this->member('developer');
        $project = $this->as($this->owner, function () use ($developer) {
            $project = $this->app->make(CreateProject::class)->handle($this->owner, 'PAT', 'Patch me');
            $this->app->make(AddProjectMember::class)->handle($this->owner, $project, $this->membershipId($developer));

            return $project;
        });

        $this->actingAs($developer)->patchJson($this->url("projects/{$project}"), ['expected_version' => 0, 'name' => 'Nope'])->assertForbidden();

        $this->actingAs($this->owner)->patchJson($this->url("projects/{$project}"), ['expected_version' => 0, 'name' => 'Renamed'])
            ->assertOk()->assertJsonPath('data.name', 'Renamed')->assertJsonPath('data.version', 1);
        $this->patchJson($this->url("projects/{$project}"), ['expected_version' => 0, 'name' => 'Stale'])->assertStatus(409);
        $this->patchJson($this->url("projects/{$project}"), ['expected_version' => 1, 'key' => 'NEW'])->assertStatus(422);
        $this->assertSame('Renamed', $this->getJson($this->url("projects/{$project}"))->json('data.name'));
    }

    public function test_create_is_idempotent_per_user_and_duplicate_keys_are_generic(): void
    {
        $admin = $this->member('org_admin');
        $headers = ['Idempotency-Key' => 'retry-0001'];

        $first = $this->actingAs($this->owner)->postJson($this->url('projects'), ['key' => 'IDM', 'name' => 'Once'], $headers)->assertCreated();
        $replay = $this->postJson($this->url('projects'), ['key' => 'IDM', 'name' => 'Once'], $headers)->assertCreated();
        $this->assertSame($first->json('data.id'), $replay->json('data.id'));
        $this->postJson($this->url('projects'), ['key' => 'IDM', 'name' => 'Changed'], $headers)->assertStatus(422);
        $this->postJson($this->url('projects'), ['key' => 'IDZ', 'name' => 'Bad key'], ['Idempotency-Key' => 'short'])->assertStatus(422);

        // Same key from another user is a different request, not a replay.
        $this->actingAs($admin)->postJson($this->url('projects'), ['key' => 'ADM', 'name' => 'Admin'], $headers)->assertCreated();

        $duplicate = $this->postJson($this->url('projects'), ['key' => 'IDM', 'name' => 'Again'])->assertStatus(422);
        $this->assertSame('This project key cannot be used.', $duplicate->json('message'));

        $this->as($this->owner, fn () => $this->assertSame(2, (int) DB::selectOne('select count(*) as total from portfolio.projects')->total));
    }

    public function test_project_creation_is_throttled(): void
    {
        $this->actingAs($this->owner);
        for ($i = 0; $i < 10; $i++) {
            $this->postJson($this->url('projects'), ['key' => 'T'.$i.'X', 'name' => 'Throttle'])->assertCreated();
        }
        $this->postJson($this->url('projects'), ['key' => 'TZZ', 'name' => 'Throttle'])->assertStatus(429);
    }

    public function test_members_and_links_over_http(): void
    {
        $developer = $this->member('developer');
        $stranger = $this->user();
        $other = $this->app->make(CreateOrganization::class)->handle($stranger, 'Other Inc');
        $foreignMembership = $this->tenant->runFor($stranger, $other, fn () => (string) DB::selectOne('select id::text as id from identity.organization_memberships where user_id = ?', [$stranger->getKey()])->id);

        [$project, $team] = $this->as($this->owner, fn () => [
            $this->app->make(CreateProject::class)->handle($this->owner, 'HTP', 'Http'),
            $this->app->make(CreateTeam::class)->handle($this->owner, 'Squad'),
        ]);
        $this->as($this->owner, fn () => $this->app->make(AssignTeamMember::class)->handle($this->owner, $team, $this->membershipId($developer)));

        $this->actingAs($this->owner);
        $this->postJson($this->url("projects/{$project}/members"), ['membership_id' => $foreignMembership])->assertStatus(422);
        $this->postJson($this->url("projects/{$project}/members"), ['membership_id' => (string) Str::uuid()])->assertStatus(422);
        $this->postJson($this->url("projects/{$project}/teams"), ['team_id' => $team])->assertNoContent();
        $this->actingAs($developer)->getJson($this->url("projects/{$project}"))->assertOk();

        $this->actingAs($this->owner)->deleteJson($this->url("projects/{$project}/teams/{$team}"))->assertNoContent();
        $this->deleteJson($this->url("projects/{$project}/teams/{$team}"))->assertNotFound();
        $this->actingAs($developer)->getJson($this->url("projects/{$project}"))->assertNotFound();

        $this->actingAs($this->owner)->postJson($this->url("projects/{$project}/members"), ['membership_id' => $this->membershipId($developer)])->assertNoContent();
        $this->deleteJson($this->url("projects/{$project}/members/{$this->membershipId($developer)}"))->assertNoContent();
        $this->assertNotSame($other->value, $this->organization->value);
    }

    public function test_team_inventory_follows_d15(): void
    {
        $developer = $this->member('developer');
        [$mine, $notMine] = $this->as($this->owner, function () use ($developer) {
            $mine = $this->app->make(CreateTeam::class)->handle($this->owner, 'Mine');
            $notMine = $this->app->make(CreateTeam::class)->handle($this->owner, 'Not mine');
            $this->app->make(AssignTeamMember::class)->handle($this->owner, $mine, $this->membershipId($developer));

            return [$mine, $notMine];
        });

        $this->assertSame(['Mine'], array_column($this->actingAs($developer)->getJson($this->url('teams'))->json('data'), 'name'));
        $this->getJson($this->url("teams/{$mine}"))->assertOk();
        $this->getJson($this->url("teams/{$notMine}"))->assertNotFound();
        $this->postJson($this->url('teams'), ['name' => 'Rogue'])->assertForbidden();

        $owner = $this->actingAs($this->owner)->getJson($this->url('teams?limit=1'))->assertOk();
        $this->assertSame(['Mine'], array_column($owner->json('data'), 'name'));
        $this->assertSame(['Not mine'], array_column($this->getJson($this->url('teams?limit=1&cursor='.urlencode((string) $owner->json('next_cursor'))))->json('data'), 'name'));

        $created = $this->postJson($this->url('teams'), ['name' => 'Child', 'parent_team_id' => $mine], ['Idempotency-Key' => 'team-0001'])->assertCreated();
        $this->assertSame($mine, $created->json('data.parent_team_id'));
        $this->postJson($this->url('teams'), ['name' => 'Ghost child', 'parent_team_id' => (string) Str::uuid()])->assertStatus(422);
        $this->postJson($this->url("teams/{$notMine}/archive"))->assertNoContent();
    }

    public function test_b3_idempotent_replay_is_reauthorized(): void
    {
        $lead = $this->member('developer');
        $parent = $this->as($this->owner, function () use ($lead) {
            $parent = $this->app->make(CreateTeam::class)->handle($this->owner, 'Parent');
            $this->app->make(AssignTeamMember::class)->handle($this->owner, $parent, $this->membershipId($lead), 'lead');

            return $parent;
        });

        $headers = ['Idempotency-Key' => 'subteam-0001'];
        $body = ['name' => 'Sub', 'parent_team_id' => $parent];
        $this->actingAs($lead)->postJson($this->url('teams'), $body, $headers)->assertCreated();
        $this->postJson($this->url('teams'), $body, $headers)->assertCreated();

        $this->as($this->owner, fn () => $this->app->make(RemoveTeamMember::class)->handle($this->owner, $parent, $this->membershipId($lead)));
        $this->actingAs($lead)->postJson($this->url('teams'), $body, $headers)->assertNotFound();
    }

    public function test_b5_writes_are_throttled(): void
    {
        $project = $this->as($this->owner, fn () => $this->app->make(CreateProject::class)->handle($this->owner, 'THR', 'Throttle'));
        $this->actingAs($this->owner);
        for ($i = 0; $i < 60; $i++) {
            $this->deleteJson($this->url("projects/{$project}/teams/".Str::uuid()))->assertNotFound();
        }
        $this->deleteJson($this->url("projects/{$project}/teams/".Str::uuid()))->assertStatus(429);
    }

    public function test_b6_rejected_input_is_422_never_500(): void
    {
        $project = $this->as($this->owner, fn () => $this->app->make(CreateProject::class)->handle($this->owner, 'INP', 'Input'));
        $this->actingAs($this->owner);

        $this->patchJson($this->url("projects/{$project}"), ['expected_version' => 0, 'name' => "bad\0name"])->assertStatus(422);
        $this->patchJson($this->url("projects/{$project}"), ['expected_version' => 0, 'name' => '   '])->assertStatus(422);
        $this->postJson($this->url('teams'), ['name' => "n\0ul"])->assertStatus(422);
        $this->assertSame('Input', $this->getJson($this->url("projects/{$project}"))->json('data.name'));
    }

    private function url(string $path): string
    {
        return "/api/v1/organizations/{$this->organization->value}/{$path}";
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
}
