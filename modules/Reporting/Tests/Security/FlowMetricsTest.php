<?php

namespace Modules\Reporting\Tests\Security;

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
use Modules\Work\Application\AssignWorkItem;
use Modules\Work\Application\CreateWorkItem;
use Modules\Work\Application\TransitionWorkItem;

/**
 * 05 increment 10: flow metrics (spec "Métricas": "Cálculo desde
 * historial", "Métricas sin fuga").
 */
final class FlowMetricsTest extends SecurityTestCase
{
    private TenantContext $tenant;

    private User $owner;

    private OrganizationId $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->app->make(TenantContext::class);
        $this->owner = $this->user();
        $this->organization = $this->app->make(CreateOrganization::class)->handle($this->owner, 'Metrics Inc');
    }

    public function test_metrics_from_history_and_only_inside_scope(): void
    {
        $developer = $this->member('developer');
        [$visible, $hidden, $a] = $this->as($this->owner, function () use ($developer) {
            $visible = $this->app->make(CreateProject::class)->handle($this->owner, 'MET', 'Metrics');
            $hidden = $this->app->make(CreateProject::class)->handle($this->owner, 'HID', 'Hidden');
            $this->app->make(AddProjectMember::class)->handle($this->owner, $visible, $this->membershipId($developer));
            $item = fn (string $p, string $t) => $this->app->make(CreateWorkItem::class)->handle($this->owner, $p, ['type' => 'task', 'title' => $t]);
            [$a, $b, $c] = [$item($visible, 'Delivered'), $item($visible, 'Canceled'), $item($visible, 'In progress')];
            $item($visible, 'Waiting');
            $hiddenDone = $item($hidden, 'Hidden delivered');

            $this->finish($a);
            $this->finish($hiddenDone);
            $this->app->make(TransitionWorkItem::class)->handle($this->owner, $b, 0, 'canceled', 'Dropped');
            $this->move($c, ['ready', 'active']);
            // A started three days ago: its cycle time is about 3 days.
            // (History is append-only for the runtime: add an earlier entry instead of editing.)
            DB::insert(
                "insert into work.item_status_changes (organization_id, project_id, item_id, from_category, to_category, actor_membership_id, occurred_at)
                 values (platform.current_organization_id(), ?, ?, 'ready', 'active', ?, now() - interval '3 days')",
                [$visible, $a, $this->membershipId($this->owner)]
            );

            // Reopen and deliver again: still one delivery.
            $version = (int) DB::selectOne('select lock_version from work.items where id = ?', [$a])->lock_version;
            $version = $this->app->make(TransitionWorkItem::class)->handle($this->owner, $a, $version, 'active', 'Bug found');
            $version = $this->app->make(TransitionWorkItem::class)->handle($this->owner, $a, $version, 'review');
            $this->app->make(TransitionWorkItem::class)->handle($this->owner, $a, $version, 'done');

            return [$visible, $hidden, $a];
        });
        $url = "/api/v1/organizations/{$this->organization->value}/reports/flow";

        $owner = $this->actingAs($this->owner)->getJson($url)->assertOk();
        $this->assertSame(2, $owner->json('data.throughput'));
        $mine = $this->getJson("{$url}?project_id={$visible}")->assertOk();
        $this->assertSame(1, $mine->json('data.throughput'), 'Canceled is never delivered; a reopened item counts once.');
        $this->assertSame(1, $mine->json('data.aging_days.count'));
        $this->assertSame(1, $mine->json('data.cycle_time_days.count'));
        $this->assertGreaterThan(2.9, $mine->json('data.cycle_time_days.median'));

        // A member sees only the projects they can see.
        $this->actingAs($developer)->getJson($url)->assertOk()->assertJsonPath('data.throughput', 1);
        $this->getJson("{$url}?project_id={$hidden}")->assertNotFound();
        $this->getJson("{$url}?from=2026-02-01&to=2026-01-01")->assertStatus(422);
        $this->getJson("{$url}?from=2024-01-01&to=2026-01-01")->assertStatus(422);

        $this->assertNotSame('', $a);
    }

    public function test_viewer_metrics_follow_the_monitoring_scope(): void
    {
        $observed = $this->member('developer');
        $withGrant = $this->member('viewer');
        $withoutGrant = $this->member('viewer');
        $this->as($this->owner, function () use ($observed, $withGrant) {
            $project = $this->app->make(CreateProject::class)->handle($this->owner, 'MON', 'Monitored');
            $this->app->make(AddProjectMember::class)->handle($this->owner, $project, $this->membershipId($observed));
            $mine = $this->app->make(CreateWorkItem::class)->handle($this->owner, $project, ['type' => 'task', 'title' => 'Observed']);
            $other = $this->app->make(CreateWorkItem::class)->handle($this->owner, $project, ['type' => 'task', 'title' => 'Not observed']);
            $this->app->make(AssignWorkItem::class)->assign($this->owner, $mine, $this->membershipId($observed), 'owner');
            $this->finish($mine);
            $this->finish($other);
            $this->app->make(IssueGrant::class)->handle($this->owner, [
                'viewer_membership_id' => $this->membershipId($withGrant), 'scope_type' => 'user',
                'target_membership_id' => $this->membershipId($observed), 'reason' => 'Follow-up',
                'expires_at' => (new \DateTimeImmutable('+30 days'))->format(DATE_ATOM),
            ]);
        });
        $url = "/api/v1/organizations/{$this->organization->value}/monitoring/metrics";

        $this->actingAs($withGrant)->getJson($url)->assertOk()->assertJsonPath('data.throughput', 1);
        $empty = $this->actingAs($withoutGrant)->getJson($url)->assertOk();
        $this->assertSame(0, $empty->json('data.throughput'));
        $this->assertNull($empty->json('data.lead_time_days.median'), 'Empty scope: no figures, never organization totals.');
        $this->actingAs($withoutGrant)->getJson("/api/v1/organizations/{$this->organization->value}/reports/flow")->assertOk()->assertJsonPath('data.throughput', 0);
    }

    private function finish(string $item): void
    {
        $this->move($item, ['ready', 'active', 'review', 'done']);
    }

    /**
     * @param  list<string>  $statuses
     */
    private function move(string $item, array $statuses): void
    {
        $version = (int) DB::selectOne('select lock_version from work.items where id = ?', [$item])->lock_version;
        foreach ($statuses as $to) {
            $version = $this->app->make(TransitionWorkItem::class)->handle($this->owner, $item, $version, $to);
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
        return $this->as($this->owner, fn () => (string) DB::selectOne(
            'select id::text as id from identity.organization_memberships where user_id = ?',
            [$user->getKey()]
        )->id);
    }
}
