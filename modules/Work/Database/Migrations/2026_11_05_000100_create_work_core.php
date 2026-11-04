<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Platform\Infrastructure\Database\PostgresRoles;

/**
 * 03-work-backlog, increment 2: Work core schema (W1-W4, W9, W10, W13).
 *
 * - work.project_counters: next sequential number per project; the
 *   INSERT ... ON CONFLICT DO UPDATE row lock serializes allocation.
 * - work.items: key <PROJECT>-<n> (project keys are immutable), fixed
 *   types and status categories until 05, optimistic lock_version. The
 *   parent foreign key includes project_id, so a parent is always in the
 *   same project; hierarchy rules (cycles, depth 5, compatible types) are
 *   enforced by a SECURITY INVOKER trigger under a per-project advisory lock.
 * - work.labels / work.item_labels: labels per project; temporal rows.
 * - work.item_events: append-only activity with a sanitized diff.
 *
 * Item visibility is the project's (ProjectVisibility); RLS stays
 * organization-only. Must run as the owner role (orbixa_migrator), after
 * the Portfolio migrations.
 */
return new class extends Migration
{
    private const TABLES = ['work.project_counters', 'work.items', 'work.labels', 'work.item_labels', 'work.item_events'];

    public function up(): void
    {
        $runtime = PostgresRoles::runtime();

        DB::statement('create schema work');
        DB::statement('revoke all on schema work from public');
        DB::statement("grant usage on schema work to {$runtime}");

        DB::statement(<<<'SQL'
            create table work.project_counters (
                organization_id uuid not null,
                project_id uuid not null,
                next_number integer not null default 1 check (next_number > 0),
                primary key (organization_id, project_id),
                constraint project_counters_project_fk foreign key (organization_id, project_id)
                    references portfolio.projects (organization_id, id)
            )
            SQL);

        DB::statement(<<<'SQL'
            create table work.items (
                id uuid not null default uuidv7() primary key,
                organization_id uuid not null references identity.organizations (id),
                project_id uuid not null,
                number integer not null check (number > 0),
                key text not null check (key ~ '^[A-Z][A-Z0-9]{1,9}-[1-9][0-9]*$'),
                type text not null check (type in ('initiative', 'epic', 'feature', 'task', 'bug', 'chore', 'incident')),
                parent_id uuid null,
                title text not null check (length(btrim(title)) between 1 and 200),
                description text null check (description is null or length(description) <= 20000),
                status text not null default 'backlog' check (status in ('backlog', 'ready', 'active', 'review', 'done', 'canceled')),
                priority text not null default 'none' check (priority in ('none', 'low', 'medium', 'high', 'urgent')),
                estimate numeric(6, 2) null check (estimate is null or estimate >= 0),
                due_on date null,
                completed_at timestamptz null,
                created_by_membership_id uuid not null,
                created_at timestamptz not null default now(),
                updated_at timestamptz not null default now(),
                lock_version bigint not null default 0,
                constraint items_organization_id_id_unique unique (organization_id, id),
                constraint items_project_scoped_unique unique (organization_id, project_id, id),
                constraint items_number_unique unique (organization_id, project_id, number),
                constraint items_key_unique unique (organization_id, key),
                constraint items_not_own_parent check (parent_id is null or parent_id <> id),
                constraint items_completed_check check ((status = 'done') = (completed_at is not null)),
                constraint items_project_fk foreign key (organization_id, project_id)
                    references portfolio.projects (organization_id, id),
                constraint items_parent_fk foreign key (organization_id, project_id, parent_id)
                    references work.items (organization_id, project_id, id),
                constraint items_created_by_fk foreign key (organization_id, created_by_membership_id)
                    references identity.organization_memberships (organization_id, id)
            )
            SQL);
        DB::statement('create index items_parent_index on work.items (organization_id, parent_id) where parent_id is not null');
        DB::statement('create index items_project_updated_index on work.items (organization_id, project_id, updated_at, id)');
        DB::statement('create index items_project_status_index on work.items (organization_id, project_id, status)');

        DB::statement(<<<'SQL'
            create function work.item_type_rank(item_type text) returns integer
            language sql immutable
            set search_path = pg_catalog, pg_temp
            as $$
                select case item_type when 'initiative' then 1 when 'epic' then 2 when 'feature' then 3 else 4 end
            $$
            SQL);
        DB::statement('revoke all on function work.item_type_rank(text) from public');
        DB::statement("grant execute on function work.item_type_rank(text) to {$runtime}");

        DB::statement(<<<'SQL'
            create function work.enforce_item_hierarchy() returns trigger
            language plpgsql security invoker
            set search_path = pg_catalog, pg_temp
            as $$
            declare
                max_depth constant integer := 5;
                parent_type text;
                parent_depth integer;
                subtree_height integer;
            begin
                if tg_op = 'UPDATE' and new.parent_id is not distinct from old.parent_id and new.type = old.type then
                    return new;
                end if;

                -- Serialize hierarchy changes per project (same key as the application).
                perform pg_advisory_xact_lock(hashtextextended('work.hierarchy:' || new.project_id::text, 0));

                -- Children must stay compatible with a changed type.
                if tg_op = 'UPDATE' and new.type <> old.type and exists (
                    select 1 from work.items c
                     where c.parent_id = new.id
                       and not (work.item_type_rank(c.type) > work.item_type_rank(new.type)
                                or (work.item_type_rank(c.type) = 4 and new.type = 'task'))
                ) then
                    raise exception 'work hierarchy: incompatible parent type' using errcode = 'check_violation';
                end if;

                if new.parent_id is null then
                    return new;
                end if;

                select p.type into parent_type from work.items p where p.id = new.parent_id;
                if parent_type is null then
                    raise exception 'work hierarchy: parent not found' using errcode = 'check_violation';
                end if;
                if not (work.item_type_rank(new.type) > work.item_type_rank(parent_type)
                        or (work.item_type_rank(new.type) = 4 and parent_type = 'task')) then
                    raise exception 'work hierarchy: incompatible parent type' using errcode = 'check_violation';
                end if;

                if exists (
                    with recursive ancestors(id, parent_id, depth) as (
                        select i.id, i.parent_id, 1 from work.items i where i.id = new.parent_id
                        union all
                        select i.id, i.parent_id, a.depth + 1
                          from work.items i join ancestors a on i.id = a.parent_id
                         where a.depth < 64
                    )
                    select 1 from ancestors a where a.id = new.id
                ) then
                    raise exception 'work hierarchy: cycle' using errcode = 'check_violation';
                end if;

                with recursive ancestors(id, parent_id, depth) as (
                    select i.id, i.parent_id, 1 from work.items i where i.id = new.parent_id
                    union all
                    select i.id, i.parent_id, a.depth + 1
                      from work.items i join ancestors a on i.id = a.parent_id
                     where a.depth < 64
                )
                select max(a.depth) into parent_depth from ancestors a;

                with recursive subtree(id, level) as (
                    select new.id, 1
                    union all
                    select i.id, s.level + 1
                      from work.items i join subtree s on i.parent_id = s.id
                     where s.level < 64 and i.id <> new.id
                )
                select max(s.level) into subtree_height from subtree s;

                if parent_depth + subtree_height > max_depth then
                    raise exception 'work hierarchy: maximum depth exceeded' using errcode = 'check_violation';
                end if;

                return new;
            end
            $$
            SQL);
        DB::statement('revoke all on function work.enforce_item_hierarchy() from public');
        DB::statement(<<<'SQL'
            create trigger items_enforce_hierarchy
                before insert or update of parent_id, type on work.items
                for each row execute function work.enforce_item_hierarchy()
            SQL);

        // W13 + review B1 pattern: new items lock the project row FOR SHARE, so
        // archiving the project cannot race an insert.
        DB::statement(<<<'SQL'
            create function work.require_open_project() returns trigger
            language plpgsql security invoker
            set search_path = pg_catalog, pg_temp
            as $$
            begin
                perform 1 from portfolio.projects p where p.id = new.project_id and p.status <> 'archived' for share;
                if not found then
                    raise exception 'work: project must not be archived' using errcode = 'check_violation';
                end if;
                return new;
            end
            $$
            SQL);
        DB::statement('revoke all on function work.require_open_project() from public');
        DB::statement(<<<'SQL'
            create trigger items_require_open_project
                before insert on work.items
                for each row execute function work.require_open_project()
            SQL);

        DB::statement(<<<'SQL'
            create table work.labels (
                id uuid not null default uuidv7() primary key,
                organization_id uuid not null,
                project_id uuid not null,
                name text not null check (length(btrim(name)) between 1 and 50),
                color text not null default '#64748b' check (color ~ '^#[0-9a-f]{6}$'),
                created_by_membership_id uuid not null,
                created_at timestamptz not null default now(),
                constraint labels_organization_id_id_unique unique (organization_id, id),
                constraint labels_project_scoped_unique unique (organization_id, project_id, id),
                constraint labels_project_fk foreign key (organization_id, project_id)
                    references portfolio.projects (organization_id, id),
                constraint labels_created_by_fk foreign key (organization_id, created_by_membership_id)
                    references identity.organization_memberships (organization_id, id)
            )
            SQL);
        DB::statement('create unique index labels_name_unique on work.labels (organization_id, project_id, lower(name))');

        DB::statement(<<<'SQL'
            create table work.item_labels (
                id uuid not null default uuidv7() primary key,
                organization_id uuid not null,
                project_id uuid not null,
                item_id uuid not null,
                label_id uuid not null,
                added_at timestamptz not null default now(),
                added_by_membership_id uuid not null,
                removed_at timestamptz null,
                removed_by_membership_id uuid null,
                constraint item_labels_organization_id_id_unique unique (organization_id, id),
                constraint item_labels_removal_check check ((removed_at is null) = (removed_by_membership_id is null)),
                constraint item_labels_item_fk foreign key (organization_id, project_id, item_id)
                    references work.items (organization_id, project_id, id),
                constraint item_labels_label_fk foreign key (organization_id, project_id, label_id)
                    references work.labels (organization_id, project_id, id),
                constraint item_labels_added_by_fk foreign key (organization_id, added_by_membership_id)
                    references identity.organization_memberships (organization_id, id),
                constraint item_labels_removed_by_fk foreign key (organization_id, removed_by_membership_id)
                    references identity.organization_memberships (organization_id, id)
            )
            SQL);
        DB::statement('create unique index item_labels_one_active on work.item_labels (organization_id, item_id, label_id) where removed_at is null');

        DB::statement(<<<'SQL'
            create table work.item_events (
                id uuid not null default uuidv7() primary key,
                organization_id uuid not null,
                item_id uuid not null,
                type text not null check (type ~ '^[a-z][a-z_]{2,40}$'),
                actor_membership_id uuid not null,
                changes jsonb not null default '{}'::jsonb check (jsonb_typeof(changes) = 'object'),
                occurred_at timestamptz not null default now(),
                constraint item_events_organization_id_id_unique unique (organization_id, id),
                constraint item_events_item_fk foreign key (organization_id, item_id)
                    references work.items (organization_id, id),
                constraint item_events_actor_fk foreign key (organization_id, actor_membership_id)
                    references identity.organization_memberships (organization_id, id)
            )
            SQL);
        DB::statement('create index item_events_item_index on work.item_events (organization_id, item_id, occurred_at, id)');

        foreach (self::TABLES as $table) {
            $this->applyTenantTemplate($table, $runtime);
        }

        // Runtime: never DELETE; activity is append-only; key, number, project and creator are immutable.
        DB::statement("grant select, insert on table work.project_counters to {$runtime}");
        DB::statement("grant update (next_number) on table work.project_counters to {$runtime}");
        DB::statement("grant select, insert on table work.items to {$runtime}");
        DB::statement("grant update (type, parent_id, title, description, status, priority, estimate, due_on, completed_at, updated_at, lock_version) on table work.items to {$runtime}");
        DB::statement("grant select, insert on table work.labels to {$runtime}");
        DB::statement("grant select, insert on table work.item_labels to {$runtime}");
        DB::statement("grant update (removed_at, removed_by_membership_id) on table work.item_labels to {$runtime}");
        DB::statement("grant select, insert on table work.item_events to {$runtime}");
    }

    public function down(): void
    {
        throw new RuntimeException('Work cannot be rolled back; restore from backup instead.');
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
