<?php

namespace Modules\Workflow\Tests\Security;

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
use Modules\Work\Application\CreateWorkItem;
use Modules\Work\Application\TransitionWorkItem;

/**
 * 05 increment 8: workflow configuration over HTTP (spec "Configuración que
 * intenta escalar"; P3 invariants).
 */
final class WorkflowConfigurationTest extends SecurityTestCase
{
    private TenantContext $tenant;

    private User $owner;

    private OrganizationId $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->app->make(TenantContext::class);
        $this->owner = $this->user();
        $this->organization = $this->app->make(CreateOrganization::class)->handle($this->owner, 'Config Inc');
    }

    public function test_configuration_rules_over_http(): void
    {
        $manager = $this->member('developer');
        $developer = $this->member('developer');
        $outsider = $this->member('developer');
        $project = $this->as($this->owner, function () use ($manager, $developer) {
            $project = $this->app->make(CreateProject::class)->handle($this->owner, 'CFG', 'Configured');
            $this->app->make(AddProjectMember::class)->handle($this->owner, $project, $this->membershipId($manager), 'manager');
            $this->app->make(AddProjectMember::class)->handle($this->owner, $project, $this->membershipId($developer));
            $this->app->make(CreateWorkItem::class)->handle($this->owner, $project, ['type' => 'task', 'title' => 'Occupies backlog']);

            return $project;
        });
        $url = fn (string $path = '') => "/api/v1/organizations/{$this->organization->value}/projects/{$project}/workflow{$path}";

        $workflow = $this->actingAs($developer)->getJson($url())->assertOk();
        $this->assertSame(1, $workflow->json('data.version'));
        $this->assertCount(6, $workflow->json('data.statuses'));
        $this->assertCount(11, $workflow->json('data.transitions'));
        $byCategory = array_column($workflow->json('data.statuses'), 'id', 'category');
        $reviewToDone = collect($workflow->json('data.transitions'))->first(fn ($t) => $t['from_status_id'] === $byCategory['review'] && $t['to_status_id'] === $byCategory['done'])['id'];

        $this->postJson($url('/statuses'), ['expected_version' => 1, 'name' => 'QA', 'category' => 'review', 'position' => 3])->assertForbidden();
        $this->actingAs($outsider)->getJson($url())->assertNotFound();

        $this->actingAs($manager);
        $qa = (string) $this->postJson($url('/statuses'), ['expected_version' => 1, 'name' => 'QA', 'category' => 'review', 'position' => 3, 'wip_limit' => 2])->assertCreated()->json('data.id');
        $this->postJson($url('/statuses'), ['expected_version' => 1, 'name' => 'Stale', 'category' => 'active', 'position' => 9])->assertStatus(409);
        $this->postJson($url('/statuses'), ['expected_version' => 2, 'name' => 'qa', 'category' => 'review', 'position' => 9])->assertStatus(422);
        $this->postJson($url('/statuses'), ['expected_version' => 2, 'name' => 'Blocked', 'category' => 'blocked', 'position' => 9])->assertStatus(422);

        // The configuration can only restrict: done always needs work.approve.
        $this->postJson($url('/transitions'), ['expected_version' => 2, 'from_status_id' => $qa, 'to_status_id' => $byCategory['done'], 'required_permission' => 'work.transition'])->assertStatus(422);
        $this->postJson($url('/transitions'), ['expected_version' => 2, 'from_status_id' => $qa, 'to_status_id' => $byCategory['done'], 'required_permission' => 'work.admin'])->assertStatus(422);
        $this->patchJson($url("/transitions/{$reviewToDone}"), ['expected_version' => 2, 'required_permission' => 'work.transition'])->assertStatus(422);
        $this->postJson($url('/transitions'), ['expected_version' => 2, 'from_status_id' => $byCategory['active'], 'to_status_id' => $qa])->assertCreated();
        $this->postJson($url('/transitions'), ['expected_version' => 3, 'from_status_id' => $byCategory['active'], 'to_status_id' => $qa])->assertStatus(422);

        // Archiving: never the initial status, never one with items, never the last done.
        $this->postJson($url("/statuses/{$byCategory['backlog']}/archive"), ['expected_version' => 3])->assertStatus(422);
        $this->postJson($url("/statuses/{$byCategory['done']}/archive"), ['expected_version' => 3])->assertStatus(422);
        $this->postJson($url("/statuses/{$qa}/archive"), ['expected_version' => 3])->assertNoContent();
        $this->assertCount(7, $this->getJson($url())->json('data.statuses'), 'Archived, never deleted.');

        $this->as($this->owner, fn () => $this->app->make(TransitionWorkItem::class)->handle(
            $this->owner, (string) DB::selectOne("select id::text as id from work.items where key = 'CFG-1'")->id, 0, 'ready'
        ));
        $this->actingAs($manager)->postJson($url("/statuses/{$byCategory['ready']}/archive"), ['expected_version' => 4])->assertStatus(422);
        $this->postJson($url("/transitions/{$reviewToDone}/disable"), ['expected_version' => 4])->assertNoContent();
        $this->as($this->owner, function () use ($project) {
            $events = DB::select("select payload->>'operation' as op, (payload->>'version')::int as v from events.domain_outbox where event_name = 'workflow.updated' and payload->>'project_id' = ? order by occurred_at, event_id", [$project]);
            $this->assertSame(['status_added', 'transition_added', 'status_archived', 'transition_disabled'], array_column($events, 'op'));
            $this->assertSame([2, 3, 4, 5], array_map(fn ($e) => (int) $e->v, $events));
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
        return $this->as($this->owner, fn () => (string) DB::selectOne(
            'select id::text as id from identity.organization_memberships where user_id = ?',
            [$user->getKey()]
        )->id);
    }
}
