<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Platform\Infrastructure\Database\PostgresRoles;

/**
 * 00-platform-foundation, task 4: transactional outbox, consumer receipts
 * and idempotency keys. All three are tenant tables following the template
 * from 2026_11_03_000200 (ENABLE + FORCE RLS, one policy TO the runtime role,
 * immutable organization_id).
 *
 * The runtime role can only append to the outbox (SELECT, INSERT). Marking
 * events as published belongs to the relay, whose cross-organization access
 * is not granted until its design is approved (docs/operations/outbox-relay.md).
 *
 * Must run as the owner role (orbixa_migrator).
 */
return new class extends Migration
{
    public function up(): void
    {
        $runtime = PostgresRoles::runtime();

        DB::statement('create schema events');
        DB::statement('revoke all on schema events from public');
        DB::statement("grant usage on schema events to {$runtime}");

        DB::statement(<<<'SQL'
            create table events.domain_outbox (
                event_id uuid not null default uuidv7() primary key,
                organization_id uuid not null,
                aggregate_type text not null check (aggregate_type <> '' and length(aggregate_type) <= 100),
                aggregate_id uuid not null,
                event_name text not null check (event_name ~ '^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$'),
                event_version integer not null check (event_version > 0),
                payload jsonb not null default '{}'::jsonb check (jsonb_typeof(payload) = 'object'),
                actor_type text not null check (actor_type in ('human', 'agent', 'service', 'system')),
                actor_id text null,
                correlation_id uuid not null,
                causation_id uuid null,
                occurred_at timestamptz not null default now(),
                available_at timestamptz not null default now(),
                published_at timestamptz null,
                attempts integer not null default 0 check (attempts >= 0),
                last_error text null,
                constraint domain_outbox_organization_id_event_id_unique unique (organization_id, event_id),
                constraint domain_outbox_actor_check check ((actor_type = 'system') = (actor_id is null))
            )
            SQL);
        DB::statement('create index domain_outbox_pending_index on events.domain_outbox (available_at) where published_at is null');

        DB::statement(<<<'SQL'
            create table events.consumer_receipts (
                organization_id uuid not null,
                consumer text not null check (consumer <> '' and length(consumer) <= 150),
                event_id uuid not null,
                processed_at timestamptz not null default now(),
                primary key (organization_id, consumer, event_id)
            )
            SQL);

        DB::statement(<<<'SQL'
            create table platform.idempotency_keys (
                id uuid not null default uuidv7() primary key,
                organization_id uuid not null,
                scope text not null check (scope <> '' and length(scope) <= 100),
                key text not null check (key <> '' and length(key) <= 200),
                request_hash text not null check (request_hash ~ '^[0-9a-f]{64}$'),
                response jsonb null,
                created_at timestamptz not null default now(),
                expires_at timestamptz not null,
                constraint idempotency_keys_organization_id_id_unique unique (organization_id, id),
                constraint idempotency_keys_scope_key_unique unique (organization_id, scope, key)
            )
            SQL);

        foreach (['events.domain_outbox', 'events.consumer_receipts', 'platform.idempotency_keys'] as $table) {
            $this->applyTenantTemplate($table, $runtime);
        }

        // Append-only for the runtime role.
        DB::statement("grant select, insert on table events.domain_outbox to {$runtime}");
        DB::statement("grant select, insert on table events.consumer_receipts to {$runtime}");
        // Reserve, read, and store the response once; expired-key purge is a later, reviewed job.
        DB::statement("grant select, insert on table platform.idempotency_keys to {$runtime}");
        DB::statement("grant update (response) on table platform.idempotency_keys to {$runtime}");
    }

    public function down(): void
    {
        // Outbox history and receipts are audit evidence: removal needs its own reviewed change.
        throw new RuntimeException('The outbox and idempotency tables cannot be rolled back.');
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
