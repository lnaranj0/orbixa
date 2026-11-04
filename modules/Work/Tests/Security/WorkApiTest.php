<?php

namespace Modules\Work\Tests\Security;

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
use Modules\Portfolio\Application\RemoveProjectMember;
use Modules\Work\Application\AssignWorkItem;
use Modules\Work\Application\CreateWorkItem;

/**
 * 03 increment 6: Work JSON API end to end over HTTP on real PostgreSQL.
 */
final class WorkApiTest extends SecurityTestCase
{
    private TenantContext $tenant;

    private User $owner;

    private OrganizationId $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->app->make(TenantContext::class);
        $this->owner = $this->user();
        $this->organization = $this->app->make(CreateOrganization::class)->handle($this->owner, 'Work Api Inc');
    }

    public function test_create_and_finish_a_task_over_http(): void
    {
        $project = $this->as($this->owner, fn () => $this->app->make(CreateProject::class)->handle($this->owner, 'ORBX', 'Orbixa'));
        $this->actingAs($this->owner);

        $headers = ['Idempotency-Key' => 'item-create-0001'];
        $created = $this->postJson($this->url("projects/{$project}/items"), ['type' => 'task', 'title' => 'Real task', 'description' => 'Do it'], $headers)->assertCreated();
        $this->assertSame('ORBX-1', $created->json('data.key'));
        $replay = $this->postJson($this->url("projects/{$project}/items"), ['type' => 'task', 'title' => 'Real task', 'description' => 'Do it'], $headers)->assertCreated();
        $this->assertSame($created->json('data.id'), $replay->json('data.id'));

        $item = (string) $created->json('data.id');
        $version = 0;
        foreach (['ready', 'active', 'review', 'done'] as $status) {
            $version = (int) $this->postJson($this->url("items/{$item}/transitions"), ['expected_version' => $version, 'to' => $status])->assertOk()->json('data.version');
        }
        $done = $this->getJson($this->url('items/by-key/ORBX-1'))->assertOk();
        $this->assertSame('done', $done->json('data.status'));
        $this->assertNotNull($done->json('data.completed_at'));

        $this->patchJson($this->url("items/{$item}"), ['expected_version' => 0, 'title' => 'Stale'])->assertStatus(409);
        $this->postJson($this->url("items/{$item}/transitions"), ['expected_version' => $version, 'to' => 'ready'])->assertStatus(422);
        $this->patchJson($this->url("items/{$item}"), ['expected_version' => $version, 'title' => "a\0b"])->assertStatus(422);

        $activity = $this->getJson($this->url("items/{$item}/activity?limit=2"))->assertOk();
        $this->assertCount(2, $activity->json('data'));
        $rest = $this->getJson($this->url("items/{$item}/activity?limit=50&cursor=".urlencode((string) $activity->json('next_cursor'))))->assertOk();
        $this->assertCount(3, $rest->json('data'));
        $this->assertStringNotContainsString('Do it', (string) $activity->getContent().$rest->getContent());
    }

    public function test_backlog_pages_filters_and_sorts_without_totals(): void
    {
        $project = $this->as($this->owner, function () {
            $project = $this->app->make(CreateProject::class)->handle($this->owner, 'BKL', 'Backlog');
            foreach (['low', 'urgent', 'none', 'high', 'medium'] as $i => $priority) {
                $this->app->make(CreateWorkItem::class)->handle($this->owner, $project, ['type' => $i % 2 === 0 ? 'task' : 'bug', 'title' => "Item {$i}", 'priority' => $priority]);
            }

            return $project;
        });
        $this->actingAs($this->owner);

        $first = $this->getJson($this->url("projects/{$project}/items?limit=2"))->assertOk();
        $this->assertSame(['BKL-1', 'BKL-2'], array_column($first->json('data'), 'key'));
        $this->assertArrayNotHasKey('total', $first->json());
        $this->assertArrayNotHasKey('description', $first->json('data.0'));
        $second = $this->getJson($this->url("projects/{$project}/items?limit=2&cursor=".urlencode((string) $first->json('next_cursor'))))->assertOk();
        $this->assertSame(['BKL-3', 'BKL-4'], array_column($second->json('data'), 'key'));

        $byPriority = $this->getJson($this->url("projects/{$project}/items?sort=priority&limit=3"))->assertOk();
        $this->assertSame(['urgent', 'high', 'medium'], array_column($byPriority->json('data'), 'priority'));
        $rest = $this->getJson($this->url("projects/{$project}/items?sort=priority&limit=3&cursor=".urlencode((string) $byPriority->json('next_cursor'))))->assertOk();
        $this->assertSame(['low', 'none'], array_column($rest->json('data'), 'priority'));

        $this->assertSame(['BKL-2', 'BKL-4'], array_column($this->getJson($this->url("projects/{$project}/items?type=bug"))->json('data'), 'key'));
        $this->getJson($this->url("projects/{$project}/items?sort=updated_at&cursor=".urlencode((string) $first->json('next_cursor'))))->assertStatus(422);
        $this->getJson($this->url("projects/{$project}/items?sort=title"))->assertStatus(422);
    }

    public function test_hidden_foreign_and_unknown_items_are_the_same_404(): void
    {
        $developer = $this->member('developer');
        [$hiddenProject, $hiddenItem] = $this->as($this->owner, function () {
            $project = $this->app->make(CreateProject::class)->handle($this->owner, 'HID', 'Hidden');

            return [$project, $this->app->make(CreateWorkItem::class)->handle($this->owner, $project, ['type' => 'task', 'title' => 'Hidden'])];
        });
        $stranger = $this->user();
        $other = $this->app->make(CreateOrganization::class)->handle($stranger, 'Other Inc');
        $foreignItem = $this->tenant->runFor($stranger, $other, function () use ($stranger) {
            $project = $this->app->make(CreateProject::class)->handle($stranger, 'FOR', 'Foreign');

            return $this->app->make(CreateWorkItem::class)->handle($stranger, $project, ['type' => 'task', 'title' => 'Foreign']);
        });

        $this->actingAs($developer);
        $responses = [
            $this->getJson($this->url("items/{$hiddenItem}")),
            $this->getJson($this->url("items/{$foreignItem}")),
            $this->getJson($this->url('items/'.Str::uuid())),
            $this->getJson($this->url('items/by-key/HID-1')),
            $this->getJson($this->url('items/by-key/FOR-1')),
            $this->getJson($this->url("projects/{$hiddenProject}/items")),
            $this->postJson($this->url("projects/{$hiddenProject}/items"), ['type' => 'task', 'title' => 'Sneaky']),
            $this->getJson($this->url("items/{$hiddenItem}/activity")),
        ];
        foreach ($responses as $response) {
            $response->assertNotFound();
            $this->assertSame($responses[0]->json('message'), $response->json('message'));
        }
    }

    public function test_my_work_only_lists_items_of_projects_still_visible(): void
    {
        $developer = $this->member('developer');
        [$kept, $lost] = $this->as($this->owner, function () use ($developer) {
            $keep = $this->app->make(CreateProject::class)->handle($this->owner, 'KEP', 'Keep');
            $lose = $this->app->make(CreateProject::class)->handle($this->owner, 'LOS', 'Lose');
            $items = [];
            foreach ([$keep, $lose] as $project) {
                $this->app->make(AddProjectMember::class)->handle($this->owner, $project, $this->membershipId($developer));
                $item = $this->app->make(CreateWorkItem::class)->handle($this->owner, $project, ['type' => 'task', 'title' => 'Mine']);
                $this->app->make(AssignWorkItem::class)->assign($this->owner, $item, $this->membershipId($developer), 'owner');
                $this->app->make(CreateWorkItem::class)->handle($this->owner, $project, ['type' => 'task', 'title' => 'Not mine']);
                $items[] = $project;
            }

            return $items;
        });

        $this->assertSame(['KEP-1', 'LOS-1'], array_column($this->actingAs($developer)->getJson($this->url('me/work'))->assertOk()->json('data'), 'key'));

        $this->as($this->owner, fn () => $this->app->make(RemoveProjectMember::class)->handle($this->owner, $lost, $this->membershipId($developer)));
        $this->assertSame(['KEP-1'], array_column($this->actingAs($developer)->getJson($this->url('me/work'))->json('data'), 'key'));
        $this->getJson($this->url('items/by-key/LOS-1'))->assertNotFound();
        $this->assertNotSame($kept, $lost);
    }

    public function test_restricted_item_detail_through_organization_role_is_audited(): void
    {
        $admin = $this->member('org_admin');
        [$project, $item] = $this->as($this->owner, function () {
            $project = $this->app->make(CreateProject::class)->handle($this->owner, 'SEC', 'Secret', 'general', 'restricted');

            return [$project, $this->app->make(CreateWorkItem::class)->handle($this->owner, $project, ['type' => 'task', 'title' => 'Classified'])];
        });

        $this->actingAs($admin)->getJson($this->url("projects/{$project}/items"))->assertOk();
        $this->as($this->owner, fn () => $this->assertSame(0, $this->audits($project)));
        $this->getJson($this->url("items/{$item}"))->assertOk();
        $this->getJson($this->url("items/{$item}"))->assertOk();
        $this->as($this->owner, fn () => $this->assertSame(1, $this->audits($project)));
    }

    private function audits(string $project): int
    {
        return (int) DB::selectOne("select count(*) as total from events.domain_outbox where event_name = 'audit.sensitive_access.granted' and aggregate_id = ? and payload->>'operation' = 'work.view'", [$project])->total;
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
