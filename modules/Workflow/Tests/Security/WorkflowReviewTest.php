<?php

namespace Modules\Workflow\Tests\Security;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\IdentityTenancy\Application\AcceptInvitation;
use Modules\IdentityTenancy\Application\CreateOrganization;
use Modules\IdentityTenancy\Application\InviteMember;
use Modules\IdentityTenancy\Contracts\MembershipReferenceInvalid;
use Modules\IdentityTenancy\Infrastructure\Persistence\User;
use Modules\Platform\Contracts\Tenancy\OrganizationId;
use Modules\Platform\Contracts\Tenancy\TenantContext;
use Modules\Platform\Contracts\Validation\InputRejected;
use Modules\Platform\Tests\Security\SecurityTestCase;
use Modules\Portfolio\Application\AddProjectMember;
use Modules\Portfolio\Application\CreateProject;
use Modules\Work\Application\CreateWorkItem;
use Modules\Work\Application\TransitionWorkItem;
use Modules\Workflow\Application\ConfigureWorkflow;

/**
 * Regressions for the 05 security review (M1, M2, M4, B9).
 */
final class WorkflowReviewTest extends SecurityTestCase
{
    private TenantContext $tenant;

    private User $owner;

    private OrganizationId $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->app->make(TenantContext::class);
        $this->owner = $this->user();
        $this->organization = $this->app->make(CreateOrganization::class)->handle($this->owner, 'Review Flow');
    }

    public function test_m1_archiving_waits_for_a_move_into_the_status(): void
    {
        [$item, $ready] = $this->as($this->owner, function () {
            $project = $this->app->make(CreateProject::class)->handle($this->owner, 'ARV', 'Archive race');
            $item = $this->item($project, 'Moving');

            return [$item, (string) DB::selectOne("select id::text as id from workflow.statuses where project_id = ? and category = 'ready'", [$project])->id];
        });

        config(['database.connections.pgsql_second' => config('database.connections.pgsql')]);
        $second = DB::connection('pgsql_second');
        DB::beginTransaction();
        try {
            $this->as($this->owner, fn () => $this->app->make(TransitionWorkItem::class)->handle($this->owner, $item, 0, 'ready'));

            $second->beginTransaction();
            $second->selectOne("select set_config('app.organization_id', ?, true)", [$this->organization->value]);
            $second->statement("set local lock_timeout = '300ms'");
            try {
                $second->selectOne('select 1 from workflow.statuses where id = ? for update', [$ready]);
                $this->fail('Archiving must wait for the move into the status.');
            } catch (QueryException $blocked) {
                $this->assertSame('55P03', $blocked->errorInfo[0]);
            } finally {
                $second->rollBack();
            }
        } finally {
            DB::rollBack();
        }
    }

    public function test_m2_no_wip_limit_on_the_initial_status(): void
    {
        $this->as($this->owner, function () {
            $project = $this->app->make(CreateProject::class)->handle($this->owner, 'WIN', 'Initial');
            $this->item($project, 'First');
            $configure = $this->app->make(ConfigureWorkflow::class);
            $initial = (string) DB::selectOne('select id::text as id from workflow.statuses where project_id = ? and is_initial', [$project])->id;
            $this->assertFailsWith(InputRejected::class, fn () => $configure->updateStatus($this->owner, $project, 1, $initial, ['wip_limit' => 3]));
        });
    }

    public function test_m4_only_working_roles_can_be_project_managers(): void
    {
        $viewer = $this->member('viewer');
        $reviewer = $this->member('reviewer');
        $this->as($this->owner, function () use ($viewer, $reviewer) {
            $project = $this->app->make(CreateProject::class)->handle($this->owner, 'PMR', 'Managers');
            foreach ([$viewer, $reviewer] as $user) {
                $this->assertFailsWith(MembershipReferenceInvalid::class, fn () => $this->app->make(AddProjectMember::class)->handle($this->owner, $project, $this->membershipId($user), 'manager'));
                $this->app->make(AddProjectMember::class)->handle($this->owner, $project, $this->membershipId($user)); // plain member is fine
            }
        });
    }

    public function test_b9_restricted_project_metrics_are_audited(): void
    {
        $admin = $this->member('org_admin');
        $project = $this->as($this->owner, fn () => $this->app->make(CreateProject::class)->handle($this->owner, 'RMT', 'Restricted', 'general', 'restricted'));

        $this->actingAs($admin)->getJson("/api/v1/organizations/{$this->organization->value}/reports/flow?project_id={$project}")->assertOk();
        $this->as($this->owner, fn () => $this->assertSame(1, (int) DB::selectOne(
            "select count(*) as total from events.domain_outbox where event_name = 'audit.sensitive_access.granted' and aggregate_id = ? and payload->>'operation' = 'metrics.view'",
            [$project]
        )->total));
    }

    private function item(string $project, string $title): string
    {
        return $this->app->make(CreateWorkItem::class)->handle($this->owner, $project, ['type' => 'task', 'title' => $title]);
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
