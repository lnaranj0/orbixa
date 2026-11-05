<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Platform\Infrastructure\Database\PostgresRoles;

/**
 * 06-github-integration (06a), increment 5, security review finding 3.
 *
 * integrations.webhook_deliveries is global (no RLS). Its
 * installation_external_id maps 1:1 to an organization, so a readable
 * register would let any tenant's code path enumerate other organizations'
 * installations and activity. The column goes, and the runtime keeps
 * INSERT only: deduplication uses INSERT ... ON CONFLICT DO NOTHING without
 * a conflict target, which needs no SELECT.
 *
 * Must run as the owner role (orbixa_migrator).
 */
return new class extends Migration
{
    public function up(): void
    {
        $runtime = PostgresRoles::runtime();

        DB::statement("revoke select on table integrations.webhook_deliveries from {$runtime}");
        DB::statement('alter table integrations.webhook_deliveries drop column installation_external_id');
    }

    public function down(): void
    {
        throw new RuntimeException('The delivery register restriction cannot be rolled back; roll forward instead.');
    }
};
