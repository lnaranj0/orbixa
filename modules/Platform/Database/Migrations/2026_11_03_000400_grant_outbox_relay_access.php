<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Platform\Infrastructure\Database\PostgresRoles;

/**
 * Outbox relay, option A (approved, docs/operations/outbox-relay.md).
 *
 * orbixa_relay has no BYPASSRLS. Two policies scoped TO the relay role:
 * - relay_read: SELECT on events.domain_outbox across organizations. It must
 *   be USING (true): PostgreSQL also checks the NEW row of an UPDATE ... WHERE
 *   against SELECT policies, so a "pending only" read policy would reject
 *   marking a row as published. Pending rows were already visible across
 *   tenants and carry the same data class (ids, metadata), so history adds
 *   volume, not a new kind of exposure.
 * - relay_mark_published: UPDATE only while the row is still pending.
 * Column grants let it change only the publication columns. It has no
 * privilege on any other table.
 *
 * Must run as the owner role (orbixa_migrator), after provisioning created
 * the relay role.
 */
return new class extends Migration
{
    public function up(): void
    {
        $relay = PostgresRoles::relay();

        DB::statement("grant usage on schema events to {$relay}");
        DB::statement("grant select on table events.domain_outbox to {$relay}");
        DB::statement("grant update (published_at, attempts, last_error, available_at) on table events.domain_outbox to {$relay}");

        DB::statement(<<<SQL
            create policy relay_read on events.domain_outbox
                as permissive for select to {$relay}
                using (true)
            SQL);
        DB::statement(<<<SQL
            create policy relay_mark_published on events.domain_outbox
                as permissive for update to {$relay}
                using (published_at is null)
                with check (true)
            SQL);
    }

    public function down(): void
    {
        $relay = PostgresRoles::relay();

        DB::statement('drop policy relay_mark_published on events.domain_outbox');
        DB::statement('drop policy relay_read on events.domain_outbox');
        DB::statement("revoke all on table events.domain_outbox from {$relay}");
        DB::statement("revoke usage on schema events from {$relay}");
    }
};
