<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 03 security review:
 * - M2: the dependency reachability search no longer has a depth cap (a
 *   chain longer than the cap could close a cycle) and deduplicates by item
 *   id, so it terminates on any graph and stays linear.
 * - B1: every write to an item or its relations takes FOR SHARE on the
 *   project row, so archiving the project (an UPDATE of that row) cannot
 *   race edits, transitions, labels, assignments, team links or
 *   dependencies. SECURITY INVOKER: RLS applies to the reads.
 *
 * Must run as the owner role (orbixa_migrator).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            create or replace function work.enforce_dependencies() returns trigger
            language plpgsql security invoker
            set search_path = pg_catalog, pg_temp
            as $$
            declare
                max_per_item constant integer := 50;
            begin
                perform pg_advisory_xact_lock(hashtextextended('work.dependencies:' || new.project_id::text, 0));

                if (select count(*) from work.item_dependencies d
                     where d.removed_at is null and (d.blocked_item_id = new.blocked_item_id or d.blocker_item_id = new.blocked_item_id)) >= max_per_item
                   or (select count(*) from work.item_dependencies d
                     where d.removed_at is null and (d.blocked_item_id = new.blocker_item_id or d.blocker_item_id = new.blocker_item_id)) >= max_per_item then
                    raise exception 'work dependencies: limit exceeded' using errcode = 'check_violation';
                end if;

                -- The new edge blocker -> blocked closes a cycle if blocked already
                -- reaches blocker. UNION over ids only: terminates on any graph.
                if exists (
                    with recursive reach(id) as (
                        select d.blocked_item_id from work.item_dependencies d
                         where d.removed_at is null and d.blocker_item_id = new.blocked_item_id
                        union
                        select d.blocked_item_id
                          from work.item_dependencies d join reach r on d.blocker_item_id = r.id
                         where d.removed_at is null
                    )
                    select 1 from reach where id = new.blocker_item_id
                ) then
                    raise exception 'work dependencies: cycle' using errcode = 'check_violation';
                end if;

                return new;
            end
            $$
            SQL);

        // Items carry project_id; relations reach it through the item (or carry it).
        DB::statement(<<<'SQL'
            create function work.require_open_project_of_item() returns trigger
            language plpgsql security invoker
            set search_path = pg_catalog, pg_temp
            as $$
            begin
                perform 1 from work.items i join portfolio.projects p on p.id = i.project_id
                 where i.id = new.item_id and p.status <> 'archived'
                   for share of p;
                if not found then
                    raise exception 'work: project must not be archived' using errcode = 'check_violation';
                end if;
                return new;
            end
            $$
            SQL);
        DB::statement('revoke all on function work.require_open_project_of_item() from public');

        DB::statement(<<<'SQL'
            create trigger items_update_require_open_project
                before update of type, parent_id, title, description, status, priority, estimate, due_on on work.items
                for each row execute function work.require_open_project()
            SQL);
        DB::statement(<<<'SQL'
            create trigger labels_require_open_project
                before insert on work.labels
                for each row execute function work.require_open_project()
            SQL);
        DB::statement(<<<'SQL'
            create trigger item_dependencies_require_open_project
                before insert on work.item_dependencies
                for each row execute function work.require_open_project()
            SQL);
        foreach (['item_labels', 'item_assignments', 'item_team_links'] as $table) {
            DB::statement(<<<SQL
                create trigger {$table}_require_open_project
                    before insert on work.{$table}
                    for each row execute function work.require_open_project_of_item()
                SQL);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Work triggers cannot be rolled back; roll forward instead.');
    }
};
