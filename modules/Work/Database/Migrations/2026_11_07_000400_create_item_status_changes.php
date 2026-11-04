<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Platform\Infrastructure\Database\PostgresRoles;

/**
 * 05-workflow-planning, increment 5 (P6): typed, append-only status history
 * that flow metrics are computed from. Reopening never deletes rows.
 *
 * Backfill from work.item_events ('created' and 'transitioned'), keyed by
 * source_event_id so it can be re-run idempotently (increment 6 repeats it
 * for events recorded in between). Status ids are resolved through the
 * project's workflow when one exists; categories are always set.
 * FORCE RLS is lifted only inside this transaction for the backfill.
 *
 * Must run as the owner role (orbixa_migrator).
 */
return new class extends Migration
{
    public $withinTransaction = true;

    private const BACKFILL_TABLES = ['work.item_events', 'work.items', 'work.item_status_changes', 'workflow.statuses'];

    public function up(): void
    {
        if (DB::transactionLevel() === 0 && ! DB::pretending()) {
            throw new RuntimeException('This migration must run inside a transaction.');
        }
        $runtime = PostgresRoles::runtime();

        DB::statement(<<<'SQL'
            create table work.item_status_changes (
                id uuid not null default uuidv7() primary key,
                organization_id uuid not null,
                project_id uuid not null,
                item_id uuid not null,
                from_status_id uuid null,
                to_status_id uuid null,
                from_category text null check (from_category in ('backlog', 'ready', 'active', 'review', 'done', 'canceled')),
                to_category text not null check (to_category in ('backlog', 'ready', 'active', 'review', 'done', 'canceled')),
                actor_membership_id uuid not null,
                occurred_at timestamptz not null default now(),
                source_event_id uuid null,
                constraint item_status_changes_organization_id_id_unique unique (organization_id, id),
                constraint item_status_changes_source_unique unique (organization_id, source_event_id),
                constraint item_status_changes_item_fk foreign key (organization_id, project_id, item_id)
                    references work.items (organization_id, project_id, id),
                constraint item_status_changes_actor_fk foreign key (organization_id, actor_membership_id)
                    references identity.organization_memberships (organization_id, id)
            )
            SQL);
        DB::statement('create index item_status_changes_item_index on work.item_status_changes (organization_id, item_id, occurred_at, id)');
        DB::statement('create index item_status_changes_project_index on work.item_status_changes (organization_id, project_id, to_category, occurred_at)');

        $name = 'work_item_status_changes';
        DB::statement('revoke all on table work.item_status_changes from public');
        DB::statement('alter table work.item_status_changes enable row level security');
        DB::statement('alter table work.item_status_changes force row level security');
        DB::statement(<<<SQL
            create policy tenant_isolation on work.item_status_changes
                as permissive for all to {$runtime}
                using (organization_id = (select platform.current_organization_id()))
                with check (organization_id = (select platform.current_organization_id()))
            SQL);
        DB::statement(<<<SQL
            create trigger {$name}_organization_id_immutable
                before update of organization_id on work.item_status_changes
                for each row when (old.organization_id is distinct from new.organization_id)
                execute function platform.prevent_organization_id_change()
            SQL);
        // Append-only for the runtime role.
        DB::statement("grant select, insert on table work.item_status_changes to {$runtime}");

        foreach (self::BACKFILL_TABLES as $table) {
            DB::statement("alter table {$table} no force row level security");
        }
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
            on conflict (organization_id, source_event_id) do nothing
            SQL);
        foreach (array_reverse(self::BACKFILL_TABLES) as $table) {
            DB::statement("alter table {$table} force row level security");
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Status history cannot be rolled back; restore from backup instead.');
    }
};
