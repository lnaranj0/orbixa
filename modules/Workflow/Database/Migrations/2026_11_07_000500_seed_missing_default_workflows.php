<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 05-workflow-planning, increment 6 (part 1): projects created after
 * migration 2026_11_07_000200 and before the code that ensures a workflow
 * on the first item get the same default workflow. Same definition and
 * same FORCE handling; idempotent.
 *
 * Must run as the owner role (orbixa_migrator).
 */
return new class extends Migration
{
    public $withinTransaction = true;

    private const TABLES = ['portfolio.projects', 'workflow.workflows', 'workflow.statuses', 'workflow.transitions'];

    public function up(): void
    {
        if (DB::transactionLevel() === 0 && ! DB::pretending()) {
            throw new RuntimeException('This migration must run inside a transaction.');
        }

        foreach (self::TABLES as $table) {
            DB::statement("alter table {$table} no force row level security");
        }

        // One statement with chained data-modifying CTEs (the migrator has no
        // TEMP privilege): new workflows -> their statuses -> their transitions.
        DB::statement(<<<'SQL'
            with new_workflows as (
                insert into workflow.workflows (organization_id, project_id)
                select p.organization_id, p.id from portfolio.projects p
                 where not exists (select 1 from workflow.workflows w where w.organization_id = p.organization_id and w.project_id = p.id)
                returning id, organization_id, project_id
            ), new_statuses as (
                insert into workflow.statuses (organization_id, project_id, workflow_id, name, category, position, is_initial)
                select w.organization_id, w.project_id, w.id, d.name, d.category, d.position, d.is_initial
                  from new_workflows w
                  cross join (values
                      ('Backlog', 'backlog', 0, true), ('Listo', 'ready', 1, false), ('En curso', 'active', 2, false),
                      ('En revisión', 'review', 3, false), ('Hecho', 'done', 4, false), ('Cancelado', 'canceled', 5, false)
                  ) as d(name, category, position, is_initial)
                returning id, organization_id, workflow_id, category
            )
            insert into workflow.transitions (organization_id, workflow_id, from_status_id, to_status_id, requires_reason, required_permission)
            select f.organization_id, f.workflow_id, f.id, t.id, e.requires_reason, e.required_permission
              from (values
                  ('backlog', 'ready', false, 'work.transition'), ('ready', 'active', false, 'work.transition'),
                  ('active', 'review', false, 'work.transition'), ('review', 'active', false, 'work.transition'),
                  ('review', 'done', false, 'work.approve'), ('done', 'active', true, 'work.transition'),
                  ('backlog', 'canceled', true, 'work.transition'), ('ready', 'canceled', true, 'work.transition'),
                  ('active', 'canceled', true, 'work.transition'), ('review', 'canceled', true, 'work.transition'),
                  ('canceled', 'backlog', true, 'work.transition')
              ) as e(from_category, to_category, requires_reason, required_permission)
              join new_statuses f on f.category = e.from_category
              join new_statuses t on t.workflow_id = f.workflow_id and t.category = e.to_category
            SQL);

        foreach (array_reverse(self::TABLES) as $table) {
            DB::statement("alter table {$table} force row level security");
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Default workflows cannot be rolled back; roll forward instead.');
    }
};
