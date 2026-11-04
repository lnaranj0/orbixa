<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Platform\Infrastructure\Database\PostgresRoles;

/**
 * 05-workflow-planning, increment 2: Workflow schema (P1, P3).
 *
 * - workflow.workflows: one per project (D-C), with a configuration version.
 * - workflow.statuses: per-project statuses with an immutable global
 *   category (the runtime cannot update it), order, optional WIP limit;
 *   archived, never deleted; exactly one initial status (category backlog).
 *   (organization_id, project_id, id, category) is unique so work.items can
 *   reference it with a composite foreign key that also pins the category.
 * - workflow.transitions: configured edges. The required permission comes
 *   from an allowlist and, for any transition into the category done, is
 *   forced to work.approve by a trigger, whoever configures it (D-A).
 *
 * Must run as the owner role (orbixa_migrator), after Portfolio.
 */
return new class extends Migration
{
    private const TABLES = ['workflow.workflows', 'workflow.statuses', 'workflow.transitions'];

    public function up(): void
    {
        $runtime = PostgresRoles::runtime();

        DB::statement('create schema workflow');
        DB::statement('revoke all on schema workflow from public');
        DB::statement("grant usage on schema workflow to {$runtime}");

        DB::statement(<<<'SQL'
            create table workflow.workflows (
                id uuid not null default uuidv7() primary key,
                organization_id uuid not null references identity.organizations (id),
                project_id uuid not null,
                version integer not null default 1 check (version > 0),
                created_at timestamptz not null default now(),
                updated_at timestamptz not null default now(),
                lock_version bigint not null default 0,
                constraint workflows_organization_id_id_unique unique (organization_id, id),
                constraint workflows_project_scoped_unique unique (organization_id, project_id, id),
                constraint workflows_one_per_project unique (organization_id, project_id),
                constraint workflows_project_fk foreign key (organization_id, project_id)
                    references portfolio.projects (organization_id, id)
            )
            SQL);

        DB::statement(<<<'SQL'
            create table workflow.statuses (
                id uuid not null default uuidv7() primary key,
                organization_id uuid not null,
                project_id uuid not null,
                workflow_id uuid not null,
                name text not null check (length(btrim(name)) between 1 and 60),
                category text not null check (category in ('backlog', 'ready', 'active', 'review', 'done', 'canceled')),
                position integer not null check (position between 0 and 1000),
                is_initial boolean not null default false,
                wip_limit integer null check (wip_limit is null or wip_limit between 1 and 1000),
                archived_at timestamptz null,
                created_at timestamptz not null default now(),
                constraint statuses_organization_id_id_unique unique (organization_id, id),
                constraint statuses_workflow_scoped_unique unique (organization_id, workflow_id, id),
                constraint statuses_category_scoped_unique unique (organization_id, project_id, id, category),
                constraint statuses_initial_is_backlog check (not is_initial or category = 'backlog'),
                constraint statuses_initial_not_archived check (not (is_initial and archived_at is not null)),
                constraint statuses_workflow_fk foreign key (organization_id, project_id, workflow_id)
                    references workflow.workflows (organization_id, project_id, id)
            )
            SQL);
        DB::statement('create unique index statuses_one_initial on workflow.statuses (organization_id, workflow_id) where is_initial');
        DB::statement('create unique index statuses_name_unique on workflow.statuses (organization_id, workflow_id, lower(name))');

        DB::statement(<<<'SQL'
            create table workflow.transitions (
                id uuid not null default uuidv7() primary key,
                organization_id uuid not null,
                workflow_id uuid not null,
                from_status_id uuid not null,
                to_status_id uuid not null,
                requires_reason boolean not null default false,
                check_blockers boolean not null default false,
                required_permission text not null default 'work.transition'
                    check (required_permission in ('work.transition', 'work.approve')),
                disabled_at timestamptz null,
                created_at timestamptz not null default now(),
                constraint transitions_organization_id_id_unique unique (organization_id, id),
                constraint transitions_not_self check (from_status_id <> to_status_id),
                constraint transitions_from_fk foreign key (organization_id, workflow_id, from_status_id)
                    references workflow.statuses (organization_id, workflow_id, id),
                constraint transitions_to_fk foreign key (organization_id, workflow_id, to_status_id)
                    references workflow.statuses (organization_id, workflow_id, id)
            )
            SQL);
        DB::statement('create unique index transitions_one_active on workflow.transitions (organization_id, workflow_id, from_status_id, to_status_id) where disabled_at is null');

        DB::statement(<<<'SQL'
            create function workflow.enforce_done_approval() returns trigger
            language plpgsql security invoker
            set search_path = pg_catalog, pg_temp
            as $$
            begin
                if exists (select 1 from workflow.statuses s where s.id = new.to_status_id and s.category = 'done')
                   and new.required_permission <> 'work.approve' then
                    raise exception 'workflow: transitions into done require work.approve' using errcode = 'check_violation';
                end if;
                return new;
            end
            $$
            SQL);
        DB::statement('revoke all on function workflow.enforce_done_approval() from public');
        DB::statement(<<<'SQL'
            create trigger transitions_done_requires_approval
                before insert or update of required_permission, to_status_id on workflow.transitions
                for each row execute function workflow.enforce_done_approval()
            SQL);

        foreach (self::TABLES as $table) {
            $this->applyTenantTemplate($table, $runtime);
        }

        // Runtime: no DELETE; categories, projects and edges are immutable
        // (statuses are archived, transitions disabled).
        DB::statement("grant select, insert on table workflow.workflows to {$runtime}");
        DB::statement("grant update (version, updated_at, lock_version) on table workflow.workflows to {$runtime}");
        DB::statement("grant select, insert on table workflow.statuses to {$runtime}");
        DB::statement("grant update (name, position, wip_limit, archived_at) on table workflow.statuses to {$runtime}");
        DB::statement("grant select, insert on table workflow.transitions to {$runtime}");
        DB::statement("grant update (requires_reason, check_blockers, required_permission, disabled_at) on table workflow.transitions to {$runtime}");
    }

    public function down(): void
    {
        throw new RuntimeException('Workflow cannot be rolled back; restore from backup instead.');
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
