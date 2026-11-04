<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Platform\Infrastructure\Database\PostgresRoles;

/**
 * 05-workflow-planning, increment 9: Planning schema (D-B, D-D, P9).
 *
 * - planning.project_settings: planning mode per project (continuous by
 *   default: a project without a row is continuous).
 * - planning.cycles: optional cycles with dates, goal and capacity; at most
 *   one active cycle per project; closing stamps closed_at.
 * - planning.cycle_items: temporal membership of items in cycles (the only
 *   place cycle membership lives; work.items has no cycle_id). Item and
 *   cycle must be in the same project (composite foreign keys); at most one
 *   open membership per item; closing records an explicit outcome
 *   (delivered, spilled, removed) and spilled items may be carried to the
 *   next cycle (carried_from_cycle_id). A trigger refuses memberships in a
 *   closed cycle, locking the cycle row FOR SHARE.
 *
 * Must run as the owner role (orbixa_migrator), after Work.
 */
return new class extends Migration
{
    private const TABLES = ['planning.project_settings', 'planning.cycles', 'planning.cycle_items'];

    public function up(): void
    {
        $runtime = PostgresRoles::runtime();

        DB::statement('create schema planning');
        DB::statement('revoke all on schema planning from public');
        DB::statement("grant usage on schema planning to {$runtime}");

        DB::statement(<<<'SQL'
            create table planning.project_settings (
                organization_id uuid not null references identity.organizations (id),
                project_id uuid not null,
                mode text not null default 'continuous' check (mode in ('continuous', 'sprint', 'hybrid')),
                updated_at timestamptz not null default now(),
                lock_version bigint not null default 0,
                primary key (organization_id, project_id),
                constraint project_settings_project_fk foreign key (organization_id, project_id)
                    references portfolio.projects (organization_id, id)
            )
            SQL);

        DB::statement(<<<'SQL'
            create table planning.cycles (
                id uuid not null default uuidv7() primary key,
                organization_id uuid not null,
                project_id uuid not null,
                name text not null check (length(btrim(name)) between 1 and 80),
                goal text null check (goal is null or length(goal) <= 500),
                starts_on date not null,
                ends_on date not null,
                capacity numeric(8, 2) null check (capacity is null or capacity >= 0),
                status text not null default 'planned' check (status in ('planned', 'active', 'closed')),
                closed_at timestamptz null,
                created_by_membership_id uuid not null,
                created_at timestamptz not null default now(),
                updated_at timestamptz not null default now(),
                lock_version bigint not null default 0,
                constraint cycles_organization_id_id_unique unique (organization_id, id),
                constraint cycles_project_scoped_unique unique (organization_id, project_id, id),
                constraint cycles_dates_check check (ends_on >= starts_on and ends_on <= starts_on + 180),
                constraint cycles_closed_check check ((status = 'closed') = (closed_at is not null)),
                constraint cycles_project_fk foreign key (organization_id, project_id)
                    references portfolio.projects (organization_id, id),
                constraint cycles_created_by_fk foreign key (organization_id, created_by_membership_id)
                    references identity.organization_memberships (organization_id, id)
            )
            SQL);
        DB::statement("create unique index cycles_one_active on planning.cycles (organization_id, project_id) where status = 'active'");
        DB::statement('create index cycles_project_index on planning.cycles (organization_id, project_id, starts_on)');

        DB::statement(<<<'SQL'
            create table planning.cycle_items (
                id uuid not null default uuidv7() primary key,
                organization_id uuid not null,
                project_id uuid not null,
                cycle_id uuid not null,
                item_id uuid not null,
                committed boolean not null default true,
                carried_from_cycle_id uuid null,
                added_at timestamptz not null default now(),
                added_by_membership_id uuid not null,
                outcome text null check (outcome in ('delivered', 'spilled', 'removed')),
                ended_at timestamptz null,
                ended_by_membership_id uuid null,
                constraint cycle_items_organization_id_id_unique unique (organization_id, id),
                constraint cycle_items_outcome_consistency check (
                    (outcome is null and ended_at is null and ended_by_membership_id is null)
                    or (outcome is not null and ended_at is not null and ended_by_membership_id is not null)
                ),
                constraint cycle_items_not_carried_from_itself check (carried_from_cycle_id is null or carried_from_cycle_id <> cycle_id),
                constraint cycle_items_cycle_fk foreign key (organization_id, project_id, cycle_id)
                    references planning.cycles (organization_id, project_id, id),
                constraint cycle_items_item_fk foreign key (organization_id, project_id, item_id)
                    references work.items (organization_id, project_id, id),
                constraint cycle_items_carried_from_fk foreign key (organization_id, project_id, carried_from_cycle_id)
                    references planning.cycles (organization_id, project_id, id),
                constraint cycle_items_added_by_fk foreign key (organization_id, added_by_membership_id)
                    references identity.organization_memberships (organization_id, id),
                constraint cycle_items_ended_by_fk foreign key (organization_id, ended_by_membership_id)
                    references identity.organization_memberships (organization_id, id)
            )
            SQL);
        DB::statement('create unique index cycle_items_one_open on planning.cycle_items (organization_id, item_id) where outcome is null');
        DB::statement('create index cycle_items_cycle_index on planning.cycle_items (organization_id, cycle_id) where outcome is null');

        DB::statement(<<<'SQL'
            create function planning.require_open_cycle() returns trigger
            language plpgsql security invoker
            set search_path = pg_catalog, pg_temp
            as $$
            begin
                perform 1 from planning.cycles c where c.id = new.cycle_id and c.status <> 'closed' for share;
                if not found then
                    raise exception 'planning: cycle is closed' using errcode = 'check_violation';
                end if;
                return new;
            end
            $$
            SQL);
        DB::statement('revoke all on function planning.require_open_cycle() from public');
        DB::statement(<<<'SQL'
            create trigger cycle_items_require_open_cycle
                before insert on planning.cycle_items
                for each row execute function planning.require_open_cycle()
            SQL);

        foreach (self::TABLES as $table) {
            $this->applyTenantTemplate($table, $runtime);
        }

        // Runtime: no DELETE; memberships are ended with an outcome, never removed.
        DB::statement("grant select, insert on table planning.project_settings to {$runtime}");
        DB::statement("grant update (mode, updated_at, lock_version) on table planning.project_settings to {$runtime}");
        DB::statement("grant select, insert on table planning.cycles to {$runtime}");
        DB::statement("grant update (name, goal, starts_on, ends_on, capacity, status, closed_at, updated_at, lock_version) on table planning.cycles to {$runtime}");
        DB::statement("grant select, insert on table planning.cycle_items to {$runtime}");
        DB::statement("grant update (outcome, ended_at, ended_by_membership_id) on table planning.cycle_items to {$runtime}");
    }

    public function down(): void
    {
        throw new RuntimeException('Planning cannot be rolled back; restore from backup instead.');
    }

    private function applyTenantTemplate(string $table, string $runtime): void
    {
        $name = str_replace('.', '_', $table);

        DB::statement("revoke all on table {$table} from public");
        DB::statement("alter table {$table} enable row level security");
        DB::statement("alter table {$table} force row level security");
        DB::statement(<<<SQL
            create policy tenant_isolation on {$table}
                as permissive for all to {$runtime}
                using (organization_id = (select platform.current_organization_id()))
                with check (organization_id = (select platform.current_organization_id()))
            SQL);
        DB::statement(<<<SQL
            create trigger {$name}_organization_id_immutable
                before update of organization_id on {$table}
                for each row when (old.organization_id is distinct from new.organization_id)
                execute function platform.prevent_organization_id_change()
            SQL);
    }
};
