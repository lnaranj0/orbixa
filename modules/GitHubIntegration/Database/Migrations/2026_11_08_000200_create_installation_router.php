<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Platform\Infrastructure\Database\PostgresRoles;

/**
 * 06-github-integration (06a), increment 3 (G-A option 1): the single
 * reviewed path that routes a GitHub webhook to its organization BEFORE any
 * tenant context exists (RLS would hide every row).
 *
 *   integrations.organization_for_installation(bigint) -> uuid
 *
 * returns the organization of an ACTIVE installation, or null. It reveals
 * nothing else and cannot list. It is SECURITY DEFINER, owned by the
 * NOLOGIN role orbixa_installation_reader, which sees the table only through
 * its own SELECT policy (active rows) and only the three columns it needs.
 * The runtime can execute it; nobody else.
 *
 * Must run as the owner role (orbixa_migrator), after provisioning created
 * orbixa_installation_reader and granted it to the migrator WITH SET.
 */
return new class extends Migration
{
    public function up(): void
    {
        $runtime = PostgresRoles::runtime();
        $reader = PostgresRoles::installationReader();

        DB::statement("grant usage on schema integrations to {$reader}");
        DB::statement("grant select (organization_id, installation_external_id, status) on table integrations.github_installations to {$reader}");
        DB::statement(<<<SQL
            create policy installation_reader_read on integrations.github_installations
                as permissive for select to {$reader}
                using (status = 'active')
            SQL);

        DB::statement(<<<'SQL'
            create function integrations.organization_for_installation(p_installation_external_id bigint) returns uuid
            language sql stable security definer
            set search_path = pg_catalog, pg_temp
            as $$
                select i.organization_id
                  from integrations.github_installations i
                 where i.installation_external_id = p_installation_external_id
                   and i.status = 'active'
            $$
            SQL);

        // Privileges first, ownership last (the reader carries them over).
        DB::statement('revoke all on function integrations.organization_for_installation(bigint) from public');
        DB::statement("grant execute on function integrations.organization_for_installation(bigint) to {$runtime}");
        // ALTER ... OWNER needs CREATE on the schema for the new owner: grant it
        // only for this statement (the reader keeps USAGE, not CREATE).
        DB::statement("grant create on schema integrations to {$reader}");
        DB::statement("alter function integrations.organization_for_installation(bigint) owner to {$reader}");
        DB::statement("revoke create on schema integrations from {$reader}");
    }

    public function down(): void
    {
        throw new RuntimeException('The installation router cannot be rolled back; roll forward instead.');
    }
};
