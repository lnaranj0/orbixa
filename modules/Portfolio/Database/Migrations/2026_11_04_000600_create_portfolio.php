<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Platform\Infrastructure\Database\PostgresRoles;

/**
 * 02-teams-portfolio, increment 5: Portfolio module schema (D3, D9, D12, D17).
 *
 * - portfolio.projects: key ^[A-Z][A-Z0-9]{1,9}$, unique per organization and
 *   immutable (the runtime has no UPDATE on it); standard/restricted
 *   confidentiality; never deleted.
 * - portfolio.project_members: temporal rows (member/manager) as history.
 * - portfolio.project_team_links: temporal team<->project links as history.
 *   Owned by Portfolio (not Teams) so the dependency is Portfolio -> Teams.
 *
 * Projects visibility is enforced by the application predicate
 * (ProjectVisibility); RLS stays organization-only (D1).
 *
 * Must run as the owner role (orbixa_migrator), after the Teams migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        $runtime = PostgresRoles::runtime();

        DB::statement('create schema portfolio');
        DB::statement('revoke all on schema portfolio from public');
        DB::statement("grant usage on schema portfolio to {$runtime}");

        DB::statement(<<<'SQL'
            create table portfolio.projects (
                id uuid not null default uuidv7() primary key,
                organization_id uuid not null references identity.organizations (id),
                key text not null check (key ~ '^[A-Z][A-Z0-9]{1,9}$'),
                name text not null check (length(btrim(name)) between 1 and 120),
                description text null check (description is null or length(description) <= 5000),
                type text not null default 'general' check (type in ('general', 'software', 'hybrid')),
                status text not null default 'active' check (status in ('active', 'on_hold', 'archived')),
                confidentiality text not null default 'standard' check (confidentiality in ('standard', 'restricted')),
                created_by_membership_id uuid not null,
                created_at timestamptz not null default now(),
                updated_at timestamptz not null default now(),
                lock_version bigint not null default 0,
                constraint projects_organization_id_id_unique unique (organization_id, id),
                constraint projects_key_unique unique (organization_id, key),
                constraint projects_created_by_fk foreign key (organization_id, created_by_membership_id)
                    references identity.organization_memberships (organization_id, id)
            )
            SQL);

        DB::statement(<<<'SQL'
            create table portfolio.project_members (
                id uuid not null default uuidv7() primary key,
                organization_id uuid not null,
                project_id uuid not null,
                membership_id uuid not null,
                role text not null default 'member' check (role in ('member', 'manager')),
                assigned_at timestamptz not null default now(),
                assigned_by_membership_id uuid not null,
                removed_at timestamptz null,
                removed_by_membership_id uuid null,
                removed_reason text null check (removed_reason in ('removed', 'membership_revoked')),
                constraint project_members_organization_id_id_unique unique (organization_id, id),
                constraint project_members_removal_check check ((removed_at is null) = (removed_reason is null)),
                constraint project_members_project_fk foreign key (organization_id, project_id)
                    references portfolio.projects (organization_id, id),
                constraint project_members_membership_fk foreign key (organization_id, membership_id)
                    references identity.organization_memberships (organization_id, id),
                constraint project_members_assigned_by_fk foreign key (organization_id, assigned_by_membership_id)
                    references identity.organization_memberships (organization_id, id),
                constraint project_members_removed_by_fk foreign key (organization_id, removed_by_membership_id)
                    references identity.organization_memberships (organization_id, id)
            )
            SQL);
        DB::statement('create unique index project_members_one_active on portfolio.project_members (organization_id, project_id, membership_id) where removed_at is null');
        DB::statement('create index project_members_membership_index on portfolio.project_members (organization_id, membership_id) where removed_at is null');

        DB::statement(<<<'SQL'
            create table portfolio.project_team_links (
                id uuid not null default uuidv7() primary key,
                organization_id uuid not null,
                project_id uuid not null,
                team_id uuid not null,
                linked_at timestamptz not null default now(),
                linked_by_membership_id uuid not null,
                unlinked_at timestamptz null,
                unlinked_by_membership_id uuid null,
                constraint project_team_links_organization_id_id_unique unique (organization_id, id),
                constraint project_team_links_unlink_check check ((unlinked_at is null) = (unlinked_by_membership_id is null)),
                constraint project_team_links_project_fk foreign key (organization_id, project_id)
                    references portfolio.projects (organization_id, id),
                constraint project_team_links_team_fk foreign key (organization_id, team_id)
                    references teams.teams (organization_id, id),
                constraint project_team_links_linked_by_fk foreign key (organization_id, linked_by_membership_id)
                    references identity.organization_memberships (organization_id, id),
                constraint project_team_links_unlinked_by_fk foreign key (organization_id, unlinked_by_membership_id)
                    references identity.organization_memberships (organization_id, id)
            )
            SQL);
        DB::statement('create unique index project_team_links_one_active on portfolio.project_team_links (organization_id, project_id, team_id) where unlinked_at is null');
        DB::statement('create index project_team_links_team_index on portfolio.project_team_links (organization_id, team_id) where unlinked_at is null');

        DB::statement(<<<'SQL'
            create function portfolio.require_open_project() returns trigger
            language plpgsql security invoker
            set search_path = pg_catalog, pg_temp
            as $$
            begin
                if not exists (select 1 from portfolio.projects p where p.id = new.project_id and p.status <> 'archived') then
                    raise exception 'portfolio: project must not be archived' using errcode = 'check_violation';
                end if;
                return new;
            end
            $$
            SQL);
        DB::statement('revoke all on function portfolio.require_open_project() from public');
        foreach (['project_members', 'project_team_links'] as $table) {
            DB::statement(<<<SQL
                create trigger {$table}_require_open_project
                    before insert on portfolio.{$table}
                    for each row execute function portfolio.require_open_project()
                SQL);
        }

        foreach (['portfolio.projects', 'portfolio.project_members', 'portfolio.project_team_links'] as $table) {
            $this->applyTenantTemplate($table, $runtime);
        }

        // Runtime: no DELETE; key and creator are immutable (not in the UPDATE grant).
        DB::statement("grant select, insert on table portfolio.projects to {$runtime}");
        DB::statement("grant update (name, description, type, status, confidentiality, updated_at, lock_version) on table portfolio.projects to {$runtime}");
        DB::statement("grant select, insert on table portfolio.project_members to {$runtime}");
        DB::statement("grant update (role, removed_at, removed_by_membership_id, removed_reason) on table portfolio.project_members to {$runtime}");
        DB::statement("grant select, insert on table portfolio.project_team_links to {$runtime}");
        DB::statement("grant update (unlinked_at, unlinked_by_membership_id) on table portfolio.project_team_links to {$runtime}");
    }

    public function down(): void
    {
        throw new RuntimeException('Portfolio cannot be rolled back; restore from backup instead.');
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
