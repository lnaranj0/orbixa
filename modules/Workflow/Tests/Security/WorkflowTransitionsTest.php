<?php

namespace Modules\Workflow\Tests\Security;

use Illuminate\Database\QueryException;
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
use Modules\Work\Application\AssignWorkItem;
use Modules\Work\Application\ChangeDependencies;
use Modules\Work\Application\CreateWorkItem;
use Modules\Work\Application\TransitionWorkItem;
use Modules\Work\Contracts\WorkOperationDenied;
use Modules\Work\Contracts\WorkRuleViolation;

/**
 * 05 increment 7: transitions follow the project's configured workflow
 * (spec "Flujo continuo", "Transiciones controladas", "Cierre y reapertura").
 */
final class WorkflowTransitionsTest extends SecurityTestCase
{
    private TenantContext $tenant;

    private User $owner;

    private OrganizationId $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->app->make(TenantContext::class);
        $this->owner = $this->user();
        $this->organization = $this->app->make(CreateOrganization::class)->handle($this->owner, 'Flow Inc');
    }

    public function test_first_item_creates_the_default_workflow_and_history(): void
    {
        $this->as($this->owner, function () {
            $project = $this->app->make(CreateProject::class)->handle($this->owner, 'FLW', 'Flow');
            $item = $this->item($project, 'First');

            $this->assertSame(6, (int) DB::selectOne('select count(*) as total from workflow.statuses where project_id = ?', [$project])->total);
            $this->assertSame(11, (int) DB::selectOne('select count(*) as total from workflow.transitions t join workflow.statuses s on s.id = t.from_status_id where s.project_id = ?', [$project])->total);
            $row = DB::selectOne('select i.status, s.is_initial from work.items i join workflow.statuses s on s.id = i.workflow_status_id where i.id = ?', [$item]);
            $this->assertSame(['backlog', true], [$row->status, (bool) $row->is_initial]);

            $version = 0;
            foreach (['ready', 'active', 'review', 'done'] as $to) {
                $version = $this->app->make(TransitionWorkItem::class)->handle($this->owner, $item, $version, $to);
            }
            $version = $this->app->make(TransitionWorkItem::class)->handle($this->owner, $item, $version, 'active', 'Reopened after QA');
            $this->app->make(TransitionWorkItem::class)->handle($this->owner, $item, $version, 'review');

            // Reopening keeps the whole typed history (spec "Cierre y reapertura").
            $this->assertSame(
                ['backlog', 'ready', 'active', 'review', 'done', 'active', 'review'],
                array_column(DB::select('select to_category from work.item_status_changes where item_id = ? order by occurred_at, id', [$item]), 'to_category')
            );
            $this->assertSame(2, (int) DB::selectOne("select event_version from events.domain_outbox where event_name = 'work.item.transitioned' and aggregate_id = ? limit 1", [$item])->event_version);
        });
    }

    public function test_done_needs_approval_and_unconfigured_transitions_are_audited(): void
    {
        $assignee = $this->member('developer');
        [$project, $item] = $this->as($this->owner, function () use ($assignee) {
            $project = $this->app->make(CreateProject::class)->handle($this->owner, 'APR', 'Approval');
            $this->app->make(AddProjectMember::class)->handle($this->owner, $project, $this->membershipId($assignee));
            $item = $this->item($project, 'Mine');
            $this->app->make(AssignWorkItem::class)->assign($this->owner, $item, $this->membershipId($assignee), 'owner');

            return [$project, $item];
        });

        $this->as($assignee, function () use ($assignee, $item) {
            $transition = $this->app->make(TransitionWorkItem::class);
            $version = 0;
            foreach (['ready', 'active', 'review'] as $to) {
                $version = $transition->handle($assignee, $item, $version, $to);
            }
            // D-A: the assignee cannot close their own task.
            $this->assertFailsWith(WorkOperationDenied::class, fn () => $transition->handle($assignee, $item, $version, 'done'));
        });

        // Not configured: 422, and the rejected attempt survives the rollback (audited once per window).
        foreach ([1, 2] as $attempt) {
            try {
                $this->as($this->owner, fn () => $this->app->make(TransitionWorkItem::class)->handle($this->owner, $item, 3, 'backlog'));
                $this->fail('Expected a rejected transition.');
            } catch (WorkRuleViolation $violation) {
                $this->assertSame(WorkRuleViolation::TRANSITION, $violation->getMessage());
            }
        }
        $this->as($this->owner, function () use ($item, $project) {
            $this->assertSame('review', DB::selectOne('select status from work.items where id = ?', [$item])->status);
            $this->assertSame(1, (int) DB::selectOne("select count(*) as total from events.domain_outbox where event_name = 'work.item.transition_rejected' and aggregate_id = ?", [$item])->total);
            $this->assertSame($project, DB::selectOne("select payload->>'project_id' as p from events.domain_outbox where event_name = 'work.item.transition_rejected'")->p);
            $this->assertSame(4, $this->app->make(TransitionWorkItem::class)->handle($this->owner, $item, 3, 'done'));
        });
    }

    public function test_reasons_blockers_and_wip_limits(): void
    {
        $this->as($this->owner, function () {
            $project = $this->app->make(CreateProject::class)->handle($this->owner, 'WIP', 'Limits');
            [$a, $b, $blocker] = [$this->item($project, 'A'), $this->item($project, 'B'), $this->item($project, 'Blocker')];
            $transition = $this->app->make(TransitionWorkItem::class);

            $this->assertRule(WorkRuleViolation::REASON, fn () => $transition->handle($this->owner, $a, 0, 'canceled'));

            // Blockers checked only where the workflow asks for it.
            DB::update("update workflow.transitions t set check_blockers = true from workflow.statuses f, workflow.statuses s
                         where f.id = t.from_status_id and s.id = t.to_status_id and f.project_id = ? and f.category = 'ready' and s.category = 'active'", [$project]);
            $this->app->make(ChangeDependencies::class)->add($this->owner, $a, $blocker);
            $transition->handle($this->owner, $a, 0, 'ready');
            $this->assertRule(WorkRuleViolation::BLOCKED, fn () => $transition->handle($this->owner, $a, 1, 'active'));
            $transition->handle($this->owner, $blocker, 0, 'canceled', 'Not needed');
            $transition->handle($this->owner, $a, 1, 'active');

            // WIP limit 1 on the active column: a second item is refused without counts.
            DB::update("update workflow.statuses set wip_limit = 1 where project_id = ? and category = 'active'", [$project]);
            $transition->handle($this->owner, $b, 0, 'ready');
            try {
                DB::transaction(fn () => $transition->handle($this->owner, $b, 1, 'active'));
                $this->fail('Expected the WIP limit.');
            } catch (WorkRuleViolation $violation) {
                $this->assertSame(WorkRuleViolation::WIP, $violation->getMessage());
                $this->assertDoesNotMatchRegularExpression('/\d/', $violation->getMessage());
            }
        });
    }

    public function test_concurrent_moves_into_a_wip_column_wait_for_each_other(): void
    {
        [$project, $a, $status] = $this->as($this->owner, function () {
            $project = $this->app->make(CreateProject::class)->handle($this->owner, 'WPC', 'Concurrent');
            $a = $this->item($project, 'A');
            DB::update("update workflow.statuses set wip_limit = 1 where project_id = ? and category = 'ready'", [$project]);

            return [$project, $a, (string) DB::selectOne("select id::text as id from workflow.statuses where project_id = ? and category = 'ready'", [$project])->id];
        });

        config(['database.connections.pgsql_second' => config('database.connections.pgsql')]);
        $second = DB::connection('pgsql_second');
        DB::beginTransaction();
        try {
            $this->as($this->owner, fn () => $this->app->make(TransitionWorkItem::class)->handle($this->owner, $a, 0, 'ready'));

            $second->beginTransaction();
            $second->statement("set local lock_timeout = '300ms'");
            try {
                $second->selectOne('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['work.wip:'.$status]);
                $this->fail('A second move into the column must wait for the first transaction.');
            } catch (QueryException $blocked) {
                $this->assertSame('55P03', $blocked->errorInfo[0]);
            } finally {
                $second->rollBack();
            }
        } finally {
            DB::rollBack();
        }
        $this->assertNotSame('', $project);
    }

    private function item(string $project, string $title): string
    {
        return $this->app->make(CreateWorkItem::class)->handle($this->owner, $project, ['type' => 'task', 'title' => $title]);
    }

    private function assertRule(string $rule, \Closure $operation): void
    {
        try {
            DB::transaction($operation);
            $this->fail('Expected WorkRuleViolation.');
        } catch (WorkRuleViolation $violation) {
            $this->assertSame($rule, $violation->getMessage());
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
