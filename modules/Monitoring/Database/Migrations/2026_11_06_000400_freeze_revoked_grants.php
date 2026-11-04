<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 04 security review M-2: a revoked grant is final. The runtime role may
 * update the revocation columns (to revoke), so without this trigger it
 * could also clear them and revive a grant; the application guard alone
 * is not enough.
 *
 * Must run as the owner role (orbixa_migrator).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            create function monitoring.prevent_revoked_grant_changes() returns trigger
            language plpgsql security invoker
            set search_path = pg_catalog, pg_temp
            as $$
            begin
                if old.revoked_at is not null then
                    raise exception 'monitoring: revoked grants are immutable' using errcode = 'check_violation';
                end if;
                return new;
            end
            $$
            SQL);
        DB::statement('revoke all on function monitoring.prevent_revoked_grant_changes() from public');
        DB::statement(<<<'SQL'
            create trigger grants_revoked_are_immutable
                before update on monitoring.grants
                for each row execute function monitoring.prevent_revoked_grant_changes()
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Monitoring triggers are roll-forward only.');
    }
};
