<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Platform\Infrastructure\Database\PostgresRoles;

/**
 * 05-workflow-planning, increment 4 (P4 step 2): items reference their
 * workflow status. status stays as the denormalized global category; the
 * composite foreign key (organization, project, status id, category) makes
 * the database guarantee that the status belongs to the item's project and
 * that both columns agree. Nullable until increment 6 (deployed with the
 * code that fills it).
 *
 * Backfill: each item gets the status of its project's workflow with the
 * same category (the default workflow has exactly one per category).
 * work.items and workflow.statuses FORCE RLS for the runtime only, so FORCE
 * is lifted inside this transaction and restored. Idempotent.
 *
 * Must run as the owner role (orbixa_migrator), after the Workflow migrations.
 */
return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        if (DB::transactionLevel() === 0 && ! DB::pretending()) {
            throw new RuntimeException('This migration must run inside a transaction.');
        }
        $runtime = PostgresRoles::runtime();

        DB::statement('alter table work.items add column workflow_status_id uuid null');
        DB::statement(<<<'SQL'
            alter table work.items add constraint items_workflow_status_fk
                foreign key (organization_id, project_id, workflow_status_id, status)
                references workflow.statuses (organization_id, project_id, id, category)
            SQL);
        DB::statement('create index items_workflow_status_index on work.items (organization_id, project_id, workflow_status_id)');
        DB::statement("grant update (workflow_status_id) on table work.items to {$runtime}");

        foreach (['work.items', 'workflow.statuses'] as $table) {
            DB::statement("alter table {$table} no force row level security");
        }
        DB::statement(<<<'SQL'
            update work.items i set workflow_status_id = (
                select s.id from workflow.statuses s
                 where s.organization_id = i.organization_id and s.project_id = i.project_id
                   and s.category = i.status and s.archived_at is null
                 order by s.position, s.id limit 1
            )
            where i.workflow_status_id is null
            SQL);
        foreach (['workflow.statuses', 'work.items'] as $table) {
            DB::statement("alter table {$table} force row level security");
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Work workflow status cannot be rolled back; roll forward instead.');
    }
};
