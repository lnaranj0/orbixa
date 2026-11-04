<?php

namespace Modules\Workflow\Tests\Security;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\IdentityTenancy\Application\CreateOrganization;
use Modules\IdentityTenancy\Infrastructure\Persistence\User;
use Modules\Platform\Contracts\Tenancy\OrganizationId;
use Modules\Platform\Contracts\Tenancy\TenantContext;
use Modules\Platform\Tests\Security\SecurityTestCase;
use Modules\Portfolio\Application\CreateProject;

/**
 * 05 increments 2-3: Workflow schema as the runtime role (P3, D-A).
 */
final class WorkflowSchemaTest extends SecurityTestCase
{
    private TenantContext $tenant;

    private User $owner;

    private OrganizationId $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->app->make(TenantContext::class);
        $this->owner = User::factory()->create(['email' => Str::uuid().'@example.test']);
        $this->organization = $this->app->make(CreateOrganization::class)->handle($this->owner, 'Workflow Schema');
    }

    public function test_status_and_transition_invariants(): void
    {
        $this->inOrg(function () {
            [$project, $workflow] = $this->workflow('WFS');
            $backlog = $this->addStatus(DB::connection(), $project, $workflow, 'Backlog', 'backlog', initial: true);
            $active = $this->addStatus(DB::connection(), $project, $workflow, 'Doing', 'active');
            $done = $this->addStatus(DB::connection(), $project, $workflow, 'Done', 'done');

            // One initial status, and it must be a backlog one.
            $this->assertSqlState('23505', fn (Connection $db) => $this->addStatus($db, $project, $workflow, 'Inbox', 'backlog', initial: true));
            $this->assertSqlState('23514', fn (Connection $db) => $this->addStatus($db, $project, $workflow, 'Start', 'active', initial: true));
            $this->assertSqlState('23514', fn (Connection $db) => $this->addStatus($db, $project, $workflow, 'Weird', 'blocked'));
            $this->assertSqlState('23505', fn (Connection $db) => $this->addStatus($db, $project, $workflow, 'doing', 'active'));
            // Category, project and workflow are immutable; nothing is deleted.
            foreach (['category', 'project_id', 'workflow_id', 'is_initial'] as $column) {
                $this->assertSqlState('42501', fn (Connection $db) => $db->update("update workflow.statuses set {$column} = {$column} where id = ?", [$active]));
            }
            foreach (['workflows', 'statuses', 'transitions'] as $table) {
                $this->assertSqlState('42501', fn (Connection $db) => $db->delete("delete from workflow.{$table}"));
            }

            $this->addTransition(DB::connection(), $workflow, $backlog, $active, 'work.transition');
            $this->addTransition(DB::connection(), $workflow, $active, $done, 'work.approve');
            // D-A: any transition into done requires work.approve, also on update.
            $this->assertSqlState('23514', fn (Connection $db) => $this->addTransition($db, $workflow, $backlog, $done, 'work.transition'));
            $this->assertSqlState('23514', fn (Connection $db) => $db->update("update workflow.transitions set required_permission = 'work.transition' where to_status_id = ?", [$done]));
            $this->assertSqlState('23514', fn (Connection $db) => $this->addTransition($db, $workflow, $active, $active, 'work.transition'));
            $this->assertSqlState('23514', fn (Connection $db) => $this->addTransition($db, $workflow, $done, $active, 'work.admin'));
            $this->assertSqlState('23505', fn (Connection $db) => $this->addTransition($db, $workflow, $backlog, $active, 'work.transition'));
            $this->assertSqlState('42501', fn (Connection $db) => $db->update('update workflow.transitions set to_status_id = to_status_id'));
        });
    }

    public function test_workflows_are_per_project_and_statuses_stay_in_their_workflow(): void
    {
        $this->inOrg(function () {
            [$project, $workflow] = $this->workflow('WFA');
            [$other, $otherWorkflow] = $this->workflow('WFB');
            $mine = $this->addStatus(DB::connection(), $project, $workflow, 'Backlog', 'backlog', initial: true);
            $theirs = $this->addStatus(DB::connection(), $other, $otherWorkflow, 'Backlog', 'backlog', initial: true);

            $this->assertSqlState('23505', fn (Connection $db) => $db->insert('insert into workflow.workflows (organization_id, project_id) values (platform.current_organization_id(), ?)', [$project]));
            // A status of project A cannot hang from project B's workflow.
            $this->assertSqlState('23503', fn (Connection $db) => $this->addStatus($db, $other, $workflow, 'Mixed', 'active'));
            // Transitions never cross workflows.
            $this->assertSqlState('23503', fn (Connection $db) => $this->addTransition($db, $workflow, $mine, $theirs, 'work.transition'));
        });

        $stranger = User::factory()->create(['email' => Str::uuid().'@example.test']);
        $other = $this->app->make(CreateOrganization::class)->handle($stranger, 'Other');
        $this->tenant->runFor($stranger, $other, function () {
            $this->assertSame(0, (int) DB::selectOne('select count(*) as total from workflow.workflows')->total);
            $this->assertSame(0, (int) DB::selectOne('select count(*) as total from workflow.statuses')->total);
        });
    }

    /**
     * @return array{0: string, 1: string} [project id, workflow id]
     */
    private function workflow(string $key): array
    {
        $project = $this->app->make(CreateProject::class)->handle($this->owner, $key, "Project {$key}");
        $workflow = (string) DB::selectOne(
            'insert into workflow.workflows (organization_id, project_id) values (platform.current_organization_id(), ?) returning id::text as id',
            [$project]
        )->id;

        return [$project, $workflow];
    }

    private function addStatus(Connection $db, string $project, string $workflow, string $name, string $category, bool $initial = false): string
    {
        return (string) $db->selectOne(
            'insert into workflow.statuses (organization_id, project_id, workflow_id, name, category, position, is_initial)
             values (platform.current_organization_id(), ?, ?, ?, ?, 0, ?) returning id::text as id',
            [$project, $workflow, $name, $category, $initial]
        )->id;
    }

    private function addTransition(Connection $db, string $workflow, string $from, string $to, string $permission): void
    {
        $db->insert(
            'insert into workflow.transitions (organization_id, workflow_id, from_status_id, to_status_id, required_permission)
             values (platform.current_organization_id(), ?, ?, ?, ?)',
            [$workflow, $from, $to, $permission]
        );
    }

    private function inOrg(\Closure $callback): mixed
    {
        return $this->tenant->runFor($this->owner, $this->organization, $callback);
    }
}
