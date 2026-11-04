<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Platform\Infrastructure\Database\PostgresRoles;

/**
 * 01-identity-tenancy, increment 3: organizations, memberships and the
 * narrow membership functions (option A).
 *
 * Both tables follow the tenant template (ENABLE + FORCE RLS, one canonical
 * policy TO the runtime role, immutable organization_id). organizations uses
 * organization_id = id so the same template applies.
 *
 * Membership must be verified BEFORE a tenant context exists, which RLS
 * forbids. Instead of opening reads, two SECURITY DEFINER functions owned by
 * the NOLOGIN role orbixa_membership_reader answer only:
 *   identity.has_active_membership(user, organization) -> boolean
 *   identity.organizations_for_user(user)              -> (id, name)
 * The reader sees rows only through its own SELECT policies and only the
 * columns it needs; the runtime can execute the functions but never read the
 * tables outside its tenant context.
 *
 * Must run as the owner role (orbixa_migrator), after provisioning created
 * orbixa_membership_reader and granted it to the migrator WITH SET.
 */
return new class extends Migration
{
    public function up(): void
    {
        $runtime = PostgresRoles::runtime();
        $reader = PostgresRoles::membershipReader();

        DB::statement(<<<'SQL'
            create table identity.organizations (
                id uuid not null primary key,
                organization_id uuid not null,
                name text not null check (length(btrim(name)) between 1 and 120),
                status text not null default 'active' check (status in ('active', 'suspended')),
                created_by_user_id uuid not null references identity.users (id),
                created_at timestamptz not null default now(),
                updated_at timestamptz not null default now(),
                lock_version bigint not null default 0,
                constraint organizations_organization_id_is_id check (organization_id = id),
                constraint organizations_organization_id_id_unique unique (organization_id, id)
            )
            SQL);

        DB::statement(<<<'SQL'
            create table identity.organization_memberships (
                id uuid not null default uuidv7() primary key,
                organization_id uuid not null references identity.organizations (id),
                user_id uuid not null references identity.users (id),
                status text not null default 'active' check (status in ('active', 'revoked')),
                joined_at timestamptz not null default now(),
                revoked_at timestamptz null,
                revoked_by_membership_id uuid null,
                lock_version bigint not null default 0,
                constraint organization_memberships_organization_id_id_unique unique (organization_id, id),
                constraint organization_memberships_user_unique unique (organization_id, user_id),
                constraint organization_memberships_revocation_check check ((status = 'revoked') = (revoked_at is not null)),
                constraint organization_memberships_revoked_by_fk
                    foreign key (organization_id, revoked_by_membership_id)
                    references identity.organization_memberships (organization_id, id)
            )
            SQL);
        DB::statement('create index organization_memberships_user_index on identity.organization_memberships (user_id) where status = \'active\'');

        foreach (['identity.organizations', 'identity.organization_memberships'] as $table) {
            $this->applyTenantTemplate($table, $runtime);
        }

        // Runtime: reviewed privileges; no DELETE (memberships are revoked, never deleted).
        DB::statement("grant select, insert on table identity.organizations to {$runtime}");
        DB::statement("grant update (name, updated_at, lock_version) on table identity.organizations to {$runtime}");
        DB::statement("grant select, insert on table identity.organization_memberships to {$runtime}");
        DB::statement("grant update (status, revoked_at, revoked_by_membership_id, lock_version) on table identity.organization_memberships to {$runtime}");

        // Membership reader: only the columns the functions need, across tenants.
        DB::statement("grant usage, create on schema identity to {$reader}");
        DB::statement("grant select (id, name, status) on table identity.organizations to {$reader}");
        DB::statement("grant select (organization_id, user_id, status) on table identity.organization_memberships to {$reader}");
        DB::statement("create policy membership_reader_read on identity.organizations as permissive for select to {$reader} using (true)");
        DB::statement("create policy membership_reader_read on identity.organization_memberships as permissive for select to {$reader} using (true)");

        DB::statement(<<<'SQL'
            create function identity.has_active_membership(p_user_id uuid, p_organization_id uuid) returns boolean
            language sql stable security definer
            set search_path = pg_catalog, pg_temp
            as $$
                select exists (
                    select 1
                      from identity.organization_memberships m
                      join identity.organizations o on o.id = m.organization_id
                     where m.user_id = p_user_id
                       and m.organization_id = p_organization_id
                       and m.status = 'active'
                       and o.status = 'active'
                )
            $$
            SQL);

        DB::statement(<<<'SQL'
            create function identity.organizations_for_user(p_user_id uuid) returns table (id uuid, name text)
            language sql stable security definer
            set search_path = pg_catalog, pg_temp
            as $$
                select o.id, o.name
                  from identity.organizations o
                  join identity.organization_memberships m on m.organization_id = o.id
                 where m.user_id = p_user_id
                   and m.status = 'active'
                   and o.status = 'active'
                 order by o.name, o.id
            $$
            SQL);

        foreach (['identity.has_active_membership(uuid, uuid)', 'identity.organizations_for_user(uuid)'] as $function) {
            // Privileges first, ownership last: once the reader owns the
            // function the migrator can no longer grant on it (PostgreSQL
            // carries existing grants over to the new owner).
            DB::statement("revoke all on function {$function} from public");
            DB::statement("grant execute on function {$function} to {$runtime}");
            DB::statement("alter function {$function} owner to {$reader}");
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Organizations and memberships cannot be rolled back; restore from backup instead.');
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
