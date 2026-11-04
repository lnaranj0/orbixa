<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 05-workflow-planning, increment 6 (part 2), deployed together with the
 * code that sets workflow_status_id on create and transition:
 * - backfill items still without a workflow status;
 * - repeat the idempotent status-history backfill for events recorded since
 *   2026_11_07_000400 (keyed by source_event_id);
 * - make work.items.workflow_status_id NOT NULL.
 * FORCE RLS is lifted only inside this transaction.
 *
 * Must run as the owner role (orbixa_migrator), after 2026_11_07_000500.
 */
return new class extends Migration
{
    public $withinTransaction = true;

    private const TABLES = ['work.items', 'work.item_events', 'work.item_status_changes', 'workflow.statuses'];

    public function up(): void
    {
        if (DB::transactionLevel() === 0 && ! DB::pretending()) {
            throw new RuntimeException('This migration must run inside a transaction.');
        }

        foreach (self::TABLES as $table) {
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

        DB::statement(<<<'SQL'
            insert into work.item_status_changes
                (organization_id, project_id, item_id, from_status_id, to_status_id, from_category, to_category, actor_membership_id, occurred_at, source_event_id)
            select e.organization_id, i.project_id, e.item_id,
                   (select s.id from workflow.statuses s where s.project_id = i.project_id and s.category = e.changes->'status'->>'from' order by s.position, s.id limit 1),
                   (select s.id from workflow.statuses s where s.project_id = i.project_id and s.category = e.changes->'status'->>'to' order by s.position, s.id limit 1),
                   e.changes->'status'->>'from', e.changes->'status'->>'to',
                   e.actor_membership_id, e.occurred_at, e.id
              from work.item_events e
              join work.items i on i.organization_id = e.organization_id and i.id = e.item_id
             where e.type in ('created', 'transitioned')
               and e.changes->'status'->>'to' in ('backlog', 'ready', 'active', 'review', 'done', 'canceled')
               -- rows written by the application since increment 7 have no source event
               and not exists (
                   select 1 from work.item_status_changes c
                    where c.organization_id = e.organization_id and c.item_id = e.item_id
                      and c.source_event_id is null and c.occurred_at = e.occurred_at
               )
            on conflict (organization_id, source_event_id) do nothing
            SQL);

        foreach (array_reverse(self::TABLES) as $table) {
            DB::statement("alter table {$table} force row level security");
        }

        DB::statement('alter table work.items alter column workflow_status_id set not null');
    }

    public function down(): void
    {
        throw new RuntimeException('Work workflow status cannot be rolled back; roll forward instead.');
    }
};
