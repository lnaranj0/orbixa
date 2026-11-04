<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Platform\Infrastructure\Database\PostgresRoles;

/**
 * 01-identity-tenancy, increment 4: per-organization roles (Access module).
 *
 * - access.roles: templates copied into each organization at creation.
 * - access.role_permissions: permission keys from the code catalog
 *   (Modules\Access\Contracts\Permission).
 * - access.membership_roles: roles held by ACTIVE memberships only.
 *
 * All three use the tenant RLS template. Composite foreign keys
 * (organization_id, x_id) make cross-organization references impossible
 * even though FK checks bypass RLS. The FK to
 * identity.organization_memberships is an accepted, explicit cross-module
 * database dependency (memberships are never deleted, only revoked).
 *
 * Must run as the owner role (orbixa_migrator).
 */
return new class extends Migration
{
    public function up(): void
    {
        $runtime = PostgresRoles::runtime();

        DB::statement('create schema access');
        DB::statement('revoke all on schema access from public');
        DB::statement("grant usage on schema access to {$runtime}");

        DB::statement(<<<'SQL'
            create table access.roles (
                id uuid not null default uuidv7() primary key,
                organization_id uuid not null references identity.organizations (id),
                key text not null check (key ~ '^[a-z][a-z0-9_]{1,49}$'),
                name text not null check (length(btrim(name)) between 1 and 80),
                is_system boolean not null default true,
                template_version integer not null check (template_version > 0),
                created_at timestamptz not null default now(),
                constraint roles_organization_id_id_unique unique (organization_id, id),
                constraint roles_key_unique unique (organization_id, key)
            )
            SQL);

        DB::statement(<<<'SQL'
            create table access.role_permissions (
                organization_id uuid not null,
                role_id uuid not null,
                permission text not null check (permission ~ '^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$'),
                primary key (organization_id, role_id, permission),
                constraint role_permissions_role_fk foreign key (organization_id, role_id)
                    references access.roles (organization_id, id)
            )
            SQL);

        DB::statement(<<<'SQL'
            create table access.membership_roles (
                organization_id uuid not null,
                membership_id uuid not null,
                role_id uuid not null,
                assigned_by_membership_id uuid null,
                assigned_at timestamptz not null default now(),
                primary key (organization_id, membership_id, role_id),
                constraint membership_roles_membership_fk foreign key (organization_id, membership_id)
                    references identity.organization_memberships (organization_id, id),
                constraint membership_roles_role_fk foreign key (organization_id, role_id)
                    references access.roles (organization_id, id),
                constraint membership_roles_assigned_by_fk foreign key (organization_id, assigned_by_membership_id)
                    references identity.organization_memberships (organization_id, id)
            )
            SQL);
        DB::statement('create index membership_roles_role_index on access.membership_roles (organization_id, role_id)');

        foreach (['access.roles', 'access.role_permissions', 'access.membership_roles'] as $table) {
            $this->applyTenantTemplate($table, $runtime);
        }

        // Templates are installed, never edited, in 01; role holdings can be removed.
        DB::statement("grant select, insert on table access.roles to {$runtime}");
        DB::statement("grant select, insert on table access.role_permissions to {$runtime}");
        DB::statement("grant select, insert, delete on table access.membership_roles to {$runtime}");
    }

    public function down(): void
    {
        throw new RuntimeException('Access roles cannot be rolled back; restore from backup instead.');
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
