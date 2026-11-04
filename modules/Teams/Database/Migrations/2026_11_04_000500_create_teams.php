<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Platform\Infrastructure\Database\PostgresRoles;

/**
 * 02-teams-portfolio, increment 2: Teams module schema (D7, D8, D9).
 *
 * - teams.teams: adjacency list (parent_team_id) within one organization,
 *   active/archived, never deleted.
 * - teams.team_members: temporal rows (assigned_* / removed_*) that are the
 *   assignment history; one active row per person and team.
 * - teams.team_parent_changes: insert-only history of reparenting.
 *
 * Hierarchy invariants are enforced in the database as a second barrier to
 * the application (SECURITY INVOKER trigger, so RLS still applies):
 * no cycles, max depth 5, parent must be active, no archiving with active
 * children. The trigger takes a per-organization advisory lock before
 * checking; under READ COMMITTED its queries then see the other
 * transaction's committed move, so concurrent A->B / B->A cannot both pass.
 *
 * Must run as the owner role (orbixa_migrator).
 */
return new class extends Migration
{
    public function up(): void
    {
        $runtime = PostgresRoles::runtime();

        DB::statement('create schema teams');
        DB::statement('revoke all on schema teams from public');
        DB::statement("grant usage on schema teams to {$runtime}");

        DB::statement(<<<'SQL'
            create table teams.teams (
                id uuid not null default uuidv7() primary key,
                organization_id uuid not null references identity.organizations (id),
                name text not null check (length(btrim(name)) between 1 and 120),
                parent_team_id uuid null,
                status text not null default 'active' check (status in ('active', 'archived')),
                archived_at timestamptz null,
                created_by_membership_id uuid not null,
                created_at timestamptz not null default now(),
                updated_at timestamptz not null default now(),
                lock_version bigint not null default 0,
                constraint teams_organization_id_id_unique unique (organization_id, id),
                constraint teams_not_own_parent check (parent_team_id is null or parent_team_id <> id),
                constraint teams_archived_check check ((status = 'archived') = (archived_at is not null)),
                constraint teams_parent_fk foreign key (organization_id, parent_team_id)
                    references teams.teams (organization_id, id),
                constraint teams_created_by_fk foreign key (organization_id, created_by_membership_id)
                    references identity.organization_memberships (organization_id, id)
            )
            SQL);
        DB::statement('create index teams_parent_index on teams.teams (organization_id, parent_team_id)');

        DB::statement(<<<'SQL'
            create table teams.team_members (
                id uuid not null default uuidv7() primary key,
                organization_id uuid not null,
                team_id uuid not null,
                membership_id uuid not null,
                role text not null default 'member' check (role in ('member', 'lead')),
                assigned_at timestamptz not null default now(),
                assigned_by_membership_id uuid not null,
                removed_at timestamptz null,
                removed_by_membership_id uuid null,
                removed_reason text null check (removed_reason in ('removed', 'membership_revoked')),
                constraint team_members_organization_id_id_unique unique (organization_id, id),
                constraint team_members_removal_check check ((removed_at is null) = (removed_reason is null)),
                constraint team_members_team_fk foreign key (organization_id, team_id)
                    references teams.teams (organization_id, id),
                constraint team_members_membership_fk foreign key (organization_id, membership_id)
                    references identity.organization_memberships (organization_id, id),
                constraint team_members_assigned_by_fk foreign key (organization_id, assigned_by_membership_id)
                    references identity.organization_memberships (organization_id, id),
                constraint team_members_removed_by_fk foreign key (organization_id, removed_by_membership_id)
                    references identity.organization_memberships (organization_id, id)
            )
            SQL);
        DB::statement('create unique index team_members_one_active on teams.team_members (organization_id, team_id, membership_id) where removed_at is null');
        DB::statement('create index team_members_membership_index on teams.team_members (organization_id, membership_id) where removed_at is null');

        DB::statement(<<<'SQL'
            create table teams.team_parent_changes (
                id uuid not null default uuidv7() primary key,
                organization_id uuid not null,
                team_id uuid not null,
                old_parent_team_id uuid null,
                new_parent_team_id uuid null,
                changed_by_membership_id uuid not null,
                changed_at timestamptz not null default now(),
                constraint team_parent_changes_organization_id_id_unique unique (organization_id, id),
                constraint team_parent_changes_team_fk foreign key (organization_id, team_id)
                    references teams.teams (organization_id, id),
                constraint team_parent_changes_changed_by_fk foreign key (organization_id, changed_by_membership_id)
                    references identity.organization_memberships (organization_id, id)
            )
            SQL);

        DB::statement(<<<'SQL'
            create function teams.enforce_hierarchy() returns trigger
            language plpgsql security invoker
            set search_path = pg_catalog, pg_temp
            as $$
            declare
                max_depth constant integer := 5;
                parent_status text;
                parent_depth integer;
                subtree_height integer;
            begin
                if tg_op = 'UPDATE' and new.status = 'archived' and old.status <> 'archived' then
                    if exists (select 1 from teams.teams c where c.parent_team_id = new.id and c.status = 'active') then
                        raise exception 'team hierarchy: cannot archive a team with active children' using errcode = 'check_violation';
                    end if;
                end if;

                if new.parent_team_id is null
                   or (tg_op = 'UPDATE' and new.parent_team_id is not distinct from old.parent_team_id) then
                    return new;
                end if;

                -- Serialize hierarchy changes per organization (same key as the application).
                perform pg_advisory_xact_lock(hashtextextended('teams.hierarchy:' || new.organization_id::text, 0));

                select t.status into parent_status from teams.teams t where t.id = new.parent_team_id;
                if parent_status is distinct from 'active' then
                    raise exception 'team hierarchy: parent must be an active team' using errcode = 'check_violation';
                end if;

                -- Ancestors of the new parent (cycle-safe): the team itself must not appear.
                with recursive ancestors(id, parent_team_id, depth) as (
                    select t.id, t.parent_team_id, 1 from teams.teams t where t.id = new.parent_team_id
                    union all
                    select t.id, t.parent_team_id, a.depth + 1
                      from teams.teams t join ancestors a on t.id = a.parent_team_id
                     where a.depth < 64
                )
                select max(a.depth) into parent_depth from ancestors a;

                if exists (
                    with recursive ancestors(id, parent_team_id, depth) as (
                        select t.id, t.parent_team_id, 1 from teams.teams t where t.id = new.parent_team_id
                        union all
                        select t.id, t.parent_team_id, a.depth + 1
                          from teams.teams t join ancestors a on t.id = a.parent_team_id
                         where a.depth < 64
                    )
                    select 1 from ancestors a where a.id = new.id
                ) then
                    raise exception 'team hierarchy: cycle' using errcode = 'check_violation';
                end if;

                -- Height of the subtree being placed (1 for a leaf or a new team).
                with recursive subtree(id, level) as (
                    select new.id, 1
                    union all
                    select t.id, s.level + 1
                      from teams.teams t join subtree s on t.parent_team_id = s.id
                     where s.level < 64 and t.id <> new.id
                )
                select max(s.level) into subtree_height from subtree s;

                if parent_depth + subtree_height > max_depth then
                    raise exception 'team hierarchy: maximum depth exceeded' using errcode = 'check_violation';
                end if;

                return new;
            end
            $$
            SQL);
        DB::statement('revoke all on function teams.enforce_hierarchy() from public');
        DB::statement(<<<'SQL'
            create trigger teams_enforce_hierarchy
                before insert or update of parent_team_id, status on teams.teams
                for each row execute function teams.enforce_hierarchy()
            SQL);

        DB::statement(<<<'SQL'
            create function teams.enforce_active_team_membership() returns trigger
            language plpgsql security invoker
            set search_path = pg_catalog, pg_temp
            as $$
            begin
                if not exists (select 1 from teams.teams t where t.id = new.team_id and t.status = 'active') then
                    raise exception 'team members: team must be active' using errcode = 'check_violation';
                end if;
                return new;
            end
            $$
            SQL);
        DB::statement('revoke all on function teams.enforce_active_team_membership() from public');
        DB::statement(<<<'SQL'
            create trigger team_members_require_active_team
                before insert on teams.team_members
                for each row execute function teams.enforce_active_team_membership()
            SQL);

        foreach (['teams.teams', 'teams.team_members', 'teams.team_parent_changes'] as $table) {
            $this->applyTenantTemplate($table, $runtime);
        }

        // Runtime: never DELETE; history columns are append/close only.
        DB::statement("grant select, insert on table teams.teams to {$runtime}");
        DB::statement("grant update (name, parent_team_id, status, archived_at, updated_at, lock_version) on table teams.teams to {$runtime}");
        DB::statement("grant select, insert on table teams.team_members to {$runtime}");
        DB::statement("grant update (role, removed_at, removed_by_membership_id, removed_reason) on table teams.team_members to {$runtime}");
        DB::statement("grant select, insert on table teams.team_parent_changes to {$runtime}");
    }

    public function down(): void
    {
        throw new RuntimeException('Teams cannot be rolled back; restore from backup instead.');
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
