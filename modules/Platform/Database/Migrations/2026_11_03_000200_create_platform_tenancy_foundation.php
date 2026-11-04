<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Platform\Infrastructure\Database\PostgresRoles;

/**
 * Platform tenancy foundation (00-platform-foundation, task 3):
 * - platform.current_organization_id(): fail-closed reader of the tenant GUC
 *   (NULL for absent, empty or malformed values, never an error).
 * - platform.prevent_organization_id_change(): reusable immutability trigger.
 * - platform.tenant_probe: permanent RLS regression canary.
 *
 * Template for every tenant table: ENABLE + FORCE RLS, one policy
 * TO the runtime role with identical USING and WITH CHECK,
 * UNIQUE (organization_id, id) for composite FKs, immutable organization_id.
 *
 * Must run as the owner role (orbixa_migrator).
 */
return new class extends Migration
{
    public function up(): void
    {
        $runtime = PostgresRoles::runtime();

        DB::statement('create schema platform');
        DB::statement('revoke all on schema platform from public');
        DB::statement("grant usage on schema platform to {$runtime}");

        DB::statement(<<<'SQL'
            create function platform.current_organization_id() returns uuid
            language sql stable parallel safe
            set search_path = pg_catalog, pg_temp
            as $$
                select case
                    when pg_input_is_valid(nullif(current_setting('app.organization_id', true), ''), 'uuid')
                    then nullif(current_setting('app.organization_id', true), '')::uuid
                end
            $$
            SQL);
        DB::statement('revoke execute on function platform.current_organization_id() from public');
        DB::statement("grant execute on function platform.current_organization_id() to {$runtime}");

        DB::statement(<<<'SQL'
            create function platform.prevent_organization_id_change() returns trigger
            language plpgsql
            set search_path = pg_catalog, pg_temp
            as $$
            begin
                raise exception 'organization_id is immutable' using errcode = '42501';
            end
            $$
            SQL);
        DB::statement('revoke execute on function platform.prevent_organization_id_change() from public');

        DB::statement(<<<'SQL'
            create table platform.tenant_probe (
                id uuid not null default uuidv7() primary key,
                organization_id uuid not null,
                label text not null,
                created_at timestamptz not null default now(),
                constraint tenant_probe_organization_id_id_unique unique (organization_id, id)
            )
            SQL);

        DB::statement('alter table platform.tenant_probe enable row level security');
        DB::statement('alter table platform.tenant_probe force row level security');

        DB::statement(<<<SQL
            create policy tenant_isolation on platform.tenant_probe
                as permissive for all to {$runtime}
                using (organization_id = (select platform.current_organization_id()))
                with check (organization_id = (select platform.current_organization_id()))
            SQL);

        DB::statement(<<<'SQL'
            create trigger tenant_probe_organization_id_immutable
                before update of organization_id on platform.tenant_probe
                for each row when (old.organization_id is distinct from new.organization_id)
                execute function platform.prevent_organization_id_change()
            SQL);

        DB::statement('revoke all on table platform.tenant_probe from public');
        DB::statement("grant select, insert, update, delete on table platform.tenant_probe to {$runtime}");
    }

    public function down(): void
    {
        // tenant_probe is the permanent RLS regression canary and the tenant
        // functions back every policy: removal needs its own reviewed change.
        throw new RuntimeException('The platform tenancy foundation is permanent and cannot be rolled back.');
    }
};
