<?php

namespace Modules\Planning\Tests\Security;

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
 * 05 increment 9: optional cycles over HTTP (spec "Ciclos opcionales",
 * "Cierre de ciclo", "Item de otro proyecto").
 */
final class CyclesTest extends SecurityTestCase
{
    private TenantContext $tenant;

    private User $owner;

    private OrganizationId $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->app->make(TenantContext::class);
        $this->owner = $this->user();
        $this->organization = $this->app->make(CreateOrganization::class)->handle($this->owner, 'Cycles Inc');
    }

    public function test_cycles_lifecycle(): void
    {
        $manager = $this->member('developer');
        $developer = $this->member('developer');
        $outsider = $this->member('developer');
        [$project, $other, $a, $b, $c, $foreign] = $this->as($this->owner, function () use ($manager, $developer) {
            $project = $this->app->make(CreateProject::class)->handle($this->owner, 'CYC', 'Cycles');
            $other = $this->app->make(CreateProject::class)->handle($this->owner, 'CYO', 'Other');
            $this->app->make(AddProjectMember::class)->handle($this->owner, $project, $this->membershipId($manager), 'manager');
            $this->app->make(AddProjectMember::class)->handle($this->owner, $project, $this->membershipId($developer));
            $create = fn (string $p, string $t) => $this->app->make(CreateWorkItem::class)->handle($this->owner, $p, ['type' => 'task', 'title' => $t]);

            return [$project, $other, $create($project, 'A'), $create($project, 'B'), $create($project, 'C'), $create($other, 'Foreign')];
        });
        $api = fn (string $path) => "/api/v1/organizations/{$this->organization->value}/{$path}";
        $dates = ['starts_on' => now()->toDateString(), 'ends_on' => now()->addDays(14)->toDateString()];

        // Continuous by default: no cycles, and the backlog works without them.
        $this->actingAs($manager)->postJson($api("projects/{$project}/cycles"), ['name' => 'Sprint 1'] + $dates)->assertStatus(422);
        $this->actingAs($developer)->putJson($api("projects/{$project}/planning"), ['mode' => 'sprint'])->assertForbidden();
        $this->actingAs($outsider)->getJson($api("projects/{$project}/planning"))->assertNotFound();

        $this->actingAs($manager)->putJson($api("projects/{$project}/planning"), ['mode' => 'sprint'])->assertNoContent();
        $first = (string) $this->postJson($api("projects/{$project}/cycles"), ['name' => 'Sprint 1', 'goal' => 'Ship', 'capacity' => 10] + $dates)->assertCreated()->json('data.id');
        $second = (string) $this->postJson($api("projects/{$project}/cycles"), ['name' => 'Sprint 2'] + $dates)->assertCreated()->json('data.id');
        $this->postJson($api("cycles/{$first}/start"))->assertNoContent();
        $this->postJson($api("cycles/{$second}/start"))->assertStatus(422); // one active cycle per project

        foreach ([$a, $b, $c] as $item) {
            $this->postJson($api("cycles/{$first}/items"), ['item_id' => $item])->assertNoContent();
        }
        $this->postJson($api("cycles/{$first}/items"), ['item_id' => $foreign])->assertStatus(422);
        $this->postJson($api("cycles/{$first}/items"), ['item_id' => (string) Str::uuid()])->assertStatus(422);
        $this->postJson($api("cycles/{$second}/items"), ['item_id' => $a])->assertStatus(422); // already in an open cycle

        $this->as($this->owner, function () use ($a, $c) {
            $transition = $this->app->make(TransitionWorkItem::class);
            $version = 0;
            foreach (['ready', 'active', 'review', 'done'] as $to) {
                $version = $transition->handle($this->owner, $a, $version, $to);
            }
            $transition->handle($this->owner, $c, 0, 'canceled', 'Dropped');
        });

        $closed = $this->postJson($api("cycles/{$first}/close"), ['carry_to_cycle_id' => $second])->assertOk();
        $this->assertSame(['delivered' => 1, 'spilled' => 2], $closed->json('data'));
        $this->postJson($api("cycles/{$first}/items"), ['item_id' => $b])->assertStatus(422); // closed

        $outcomes = collect($this->getJson($api("cycles/{$first}"))->assertOk()->json('data.items'))->pluck('outcome', 'item_id');
        $this->assertSame(['delivered', 'spilled', 'spilled'], [$outcomes[$a], $outcomes[$b], $outcomes[$c]]);
        $carried = $this->getJson($api("cycles/{$second}"))->json('data.items');
        $this->assertSame([$b], array_column($carried, 'item_id'), 'Only unfinished, non-canceled items are carried.');
        $this->assertSame($first, $carried[0]['carried_from_cycle_id']);

        $this->as($this->owner, function () use ($first) {
            $names = array_column(DB::select('select event_name from events.domain_outbox where aggregate_id = ? order by occurred_at, event_id', [$first]), 'event_name');
            $this->assertSame(['planning.cycle.created', 'planning.cycle.started'], array_slice($names, 0, 2));
            $this->assertContains('planning.cycle.closed', $names);
        });
        $this->assertNotSame($project, $other);
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
