<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Platform\Infrastructure\Database\PostgresRoles;

/**
 * 03-work-backlog, increment 3: Work relations (W7, W8).
 *
 * - work.item_assignments: temporal rows; one active owner per item and one
 *   active row per person (partial unique indexes). Humans only in 03; 08
 *   adds agent_id with an "exactly one" CHECK.
 * - work.item_team_links: temporal; the team must be active (trigger takes
 *   FOR SHARE on it, mirroring the foreign key). Grants no visibility.
 * - work.item_dependencies: blocker -> blocked inside one project (the
 *   foreign keys include project_id); no self-dependency, no cycles and at
 *   most 50 active dependencies per item, checked by a SECURITY INVOKER
 *   trigger under a per-project advisory lock.
 *
 * Must run as the owner role (orbixa_migrator), after work core and Teams.
 */
return new class extends Migration
{
    private const TABLES = ['work.item_assignments', 'work.item_team_links', 'work.item_dependencies'];

    public function up(): void
    {
        $runtime = PostgresRoles::runtime();

        DB::statement(<<<'SQL'
            create table work.item_assignments (
                id uuid not null default uuidv7() primary key,
                organization_id uuid not null,
                item_id uuid not null,
                membership_id uuid not null,
                role text not null check (role in ('owner', 'collaborator')),
                assigned_at timestamptz not null default now(),
                assigned_by_membership_id uuid not null,
                removed_at timestamptz null,
                removed_by_membership_id uuid null,
                removed_reason text null check (removed_reason in ('removed', 'replaced', 'membership_revoked')),
                constraint item_assignments_organization_id_id_unique unique (organization_id, id),
                constraint item_assignments_removal_check check ((removed_at is null) = (removed_reason is null)),
                constraint item_assignments_item_fk foreign key (organization_id, item_id)
                    references work.items (organization_id, id),
                constraint item_assignments_membership_fk foreign key (organization_id, membership_id)
                    references identity.organization_memberships (organization_id, id),
                constraint item_assignments_assigned_by_fk foreign key (organization_id, assigned_by_membership_id)
                    references identity.organization_memberships (organization_id, id),
                constraint item_assignments_removed_by_fk foreign key (organization_id, removed_by_membership_id)
                    references identity.organization_memberships (organization_id, id)
            )
            SQL);
        DB::statement("create unique index item_assignments_one_owner on work.item_assignments (organization_id, item_id) where role = 'owner' and removed_at is null");
        DB::statement('create unique index item_assignments_one_active on work.item_assignments (organization_id, item_id, membership_id) where removed_at is null');
        DB::statement('create index item_assignments_membership_index on work.item_assignments (organization_id, membership_id) where removed_at is null');

        DB::statement(<<<'SQL'
            create table work.item_team_links (
                id uuid not null default uuidv7() primary key,
                organization_id uuid not null,
                item_id uuid not null,
                team_id uuid not null,
                linked_at timestamptz not null default now(),
                linked_by_membership_id uuid not null,
                unlinked_at timestamptz null,
                unlinked_by_membership_id uuid null,
                constraint item_team_links_organization_id_id_unique unique (organization_id, id),
                constraint item_team_links_unlink_check check ((unlinked_at is null) = (unlinked_by_membership_id is null)),
                constraint item_team_links_item_fk foreign key (organization_id, item_id)
                    references work.items (organization_id, id),
                constraint item_team_links_team_fk foreign key (organization_id, team_id)
                    references teams.teams (organization_id, id),
                constraint item_team_links_linked_by_fk foreign key (organization_id, linked_by_membership_id)
                    references identity.organization_memberships (organization_id, id),
                constraint item_team_links_unlinked_by_fk foreign key (organization_id, unlinked_by_membership_id)
                    references identity.organization_memberships (organization_id, id)
            )
            SQL);
        DB::statement('create unique index item_team_links_one_active on work.item_team_links (organization_id, item_id, team_id) where unlinked_at is null');

        DB::statement(<<<'SQL'
            create function work.require_active_team() returns trigger
            language plpgsql security invoker
            set search_path = pg_catalog, pg_temp
            as $$
            begin
                perform 1 from teams.teams t where t.id = new.team_id and t.status = 'active' for share;
                if not found then
                    raise exception 'work: team must be active' using errcode = 'check_violation';
                end if;
                return new;
            end
            $$
            SQL);
        DB::statement('revoke all on function work.require_active_team() from public');
        DB::statement(<<<'SQL'
            create trigger item_team_links_require_active_team
                before insert on work.item_team_links
                for each row execute function work.require_active_team()
            SQL);

        DB::statement(<<<'SQL'
            create table work.item_dependencies (
                id uuid not null default uuidv7() primary key,
                organization_id uuid not null,
                project_id uuid not null,
                blocker_item_id uuid not null,
                blocked_item_id uuid not null,
                created_at timestamptz not null default now(),
                created_by_membership_id uuid not null,
                removed_at timestamptz null,
                removed_by_membership_id uuid null,
                constraint item_dependencies_organization_id_id_unique unique (organization_id, id),
                constraint item_dependencies_not_self check (blocker_item_id <> blocked_item_id),
                constraint item_dependencies_removal_check check ((removed_at is null) = (removed_by_membership_id is null)),
                constraint item_dependencies_blocker_fk foreign key (organization_id, project_id, blocker_item_id)
                    references work.items (organization_id, project_id, id),
                constraint item_dependencies_blocked_fk foreign key (organization_id, project_id, blocked_item_id)
                    references work.items (organization_id, project_id, id),
                constraint item_dependencies_created_by_fk foreign key (organization_id, created_by_membership_id)
                    references identity.organization_memberships (organization_id, id),
                constraint item_dependencies_removed_by_fk foreign key (organization_id, removed_by_membership_id)
                    references identity.organization_memberships (organization_id, id)
            )
            SQL);
        DB::statement('create unique index item_dependencies_one_active on work.item_dependencies (organization_id, blocker_item_id, blocked_item_id) where removed_at is null');
        DB::statement('create index item_dependencies_blocked_index on work.item_dependencies (organization_id, blocked_item_id) where removed_at is null');

        DB::statement(<<<'SQL'
            create function work.enforce_dependencies() returns trigger
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

                -- The new edge blocker -> blocked closes a cycle if blocked already reaches blocker.
                if exists (
                    with recursive reach(id, depth) as (
                        select d.blocked_item_id, 1 from work.item_dependencies d
                         where d.removed_at is null and d.blocker_item_id = new.blocked_item_id
                        union
                        select d.blocked_item_id, r.depth + 1
                          from work.item_dependencies d join reach r on d.blocker_item_id = r.id
                         where d.removed_at is null and r.depth < 1000
                    )
                    select 1 from reach where id = new.blocker_item_id
                ) then
                    raise exception 'work dependencies: cycle' using errcode = 'check_violation';
                end if;

                return new;
            end
            $$
            SQL);
        DB::statement('revoke all on function work.enforce_dependencies() from public');
        DB::statement(<<<'SQL'
            create trigger item_dependencies_enforce
                before insert on work.item_dependencies
                for each row execute function work.enforce_dependencies()
            SQL);

        foreach (self::TABLES as $table) {
            $this->applyTenantTemplate($table, $runtime);
        }

        // Runtime: never DELETE; rows are closed, which keeps the history.
        DB::statement("grant select, insert on table work.item_assignments to {$runtime}");
        DB::statement("grant update (removed_at, removed_by_membership_id, removed_reason) on table work.item_assignments to {$runtime}");
        DB::statement("grant select, insert on table work.item_team_links to {$runtime}");
        DB::statement("grant update (unlinked_at, unlinked_by_membership_id) on table work.item_team_links to {$runtime}");
        DB::statement("grant select, insert on table work.item_dependencies to {$runtime}");
        DB::statement("grant update (removed_at, removed_by_membership_id) on table work.item_dependencies to {$runtime}");
    }

    public function down(): void
    {
        throw new RuntimeException('Work relations cannot be rolled back; restore from backup instead.');
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
