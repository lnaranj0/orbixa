<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 05-workflow-planning, increment 3 (P4 step 1): every existing project gets
 * the default workflow, identical to the fixed base flow of 03 (W3), so
 * items keep exactly the transitions they had; the only change is D-A
 * (review -> done needs work.approve).
 *
 * portfolio.projects and the workflow tables FORCE row level security with
 * a policy for the runtime role only, so the owner role sees no rows.
 * Inside this migration's transaction FORCE is lifted, the rows are
 * inserted and FORCE is restored; any failure rolls everything back.
 * Idempotent: projects that already have a workflow are skipped.
 *
 * The definition is written out here on purpose (a migration must not change
 * meaning when the application's default evolves).
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

        DB::statement(<<<'SQL'
            insert into workflow.workflows (organization_id, project_id)
            select p.organization_id, p.id from portfolio.projects p
             where not exists (select 1 from workflow.workflows w where w.organization_id = p.organization_id and w.project_id = p.id)
            SQL);

        DB::statement(<<<'SQL'
            insert into workflow.statuses (organization_id, project_id, workflow_id, name, category, position, is_initial)
            select w.organization_id, w.project_id, w.id, d.name, d.category, d.position, d.is_initial
              from workflow.workflows w
              cross join (values
                  ('Backlog', 'backlog', 0, true),
                  ('Listo', 'ready', 1, false),
                  ('En curso', 'active', 2, false),
                  ('En revisión', 'review', 3, false),
                  ('Hecho', 'done', 4, false),
                  ('Cancelado', 'canceled', 5, false)
              ) as d(name, category, position, is_initial)
             where not exists (select 1 from workflow.statuses s where s.organization_id = w.organization_id and s.workflow_id = w.id)
            SQL);

        DB::statement(<<<'SQL'
            insert into workflow.transitions (organization_id, workflow_id, from_status_id, to_status_id, requires_reason, required_permission)
            select w.organization_id, w.id, f.id, t.id, e.requires_reason, e.required_permission
              from workflow.workflows w
              cross join (values
                  ('backlog', 'ready', false, 'work.transition'),
                  ('ready', 'active', false, 'work.transition'),
                  ('active', 'review', false, 'work.transition'),
                  ('review', 'active', false, 'work.transition'),
                  ('review', 'done', false, 'work.approve'),
                  ('done', 'active', true, 'work.transition'),
                  ('backlog', 'canceled', true, 'work.transition'),
                  ('ready', 'canceled', true, 'work.transition'),
                  ('active', 'canceled', true, 'work.transition'),
                  ('review', 'canceled', true, 'work.transition'),
                  ('canceled', 'backlog', true, 'work.transition')
              ) as e(from_category, to_category, requires_reason, required_permission)
              join workflow.statuses f on f.workflow_id = w.id and f.category = e.from_category
              join workflow.statuses t on t.workflow_id = w.id and t.category = e.to_category
             where not exists (select 1 from workflow.transitions x where x.organization_id = w.organization_id and x.workflow_id = w.id)
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
