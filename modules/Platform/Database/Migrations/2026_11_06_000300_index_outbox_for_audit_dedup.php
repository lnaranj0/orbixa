<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 04 security review M-1: audit deduplication (Portfolio sensitive access,
 * Monitoring reads and refused writes) looks up recent outbox rows by
 * event, aggregate and actor. Without this index every audited read scans
 * the whole outbox, which only grows.
 *
 * Must run as the owner role (orbixa_migrator).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            create index domain_outbox_audit_dedup_index
                on events.domain_outbox (organization_id, event_name, aggregate_id, actor_id, occurred_at desc)
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Platform indexes are roll-forward only.');
    }
};
