<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Platform\Infrastructure\Database\PostgresRoles;

/**
 * 04-monitoring-access, increment 3: Monitoring schema (M1, M5, M8, M9).
 *
 * - monitoring.grants: read-only visibility a viewer membership receives
 *   over one member (user), one team (optionally its subteams) or the whole
 *   organization. Typed targets with composite foreign keys; exactly one
 *   target per scope; nobody grants themselves; validity is bounded (at
 *   most 365 days). Scope, viewer and target are immutable: changing them
 *   means revoking and granting again. Validity is evaluated on every read,
 *   never cached.
 * - monitoring.grant_events: append-only history (created, updated,
 *   revoked, rejected attempts, membership revocations).
 *
 * RLS stays organization-only; the viewer's subset is computed by the
 * Monitoring visibility service. Must run as the owner role (orbixa_migrator).
 */
return new class extends Migration
{
    public function up(): void
    {
        $runtime = PostgresRoles::runtime();

        DB::statement('create schema monitoring');
        DB::statement('revoke all on schema monitoring from public');
        DB::statement("grant usage on schema monitoring to {$runtime}");

        DB::statement(<<<'SQL'
            create table monitoring.grants (
                id uuid not null default uuidv7() primary key,
                organization_id uuid not null references identity.organizations (id),
                viewer_membership_id uuid not null,
                scope_type text not null check (scope_type in ('user', 'team', 'organization')),
                target_membership_id uuid null,
                target_team_id uuid null,
                include_descendants boolean not null default false,
                visibility_level text not null default 'standard' check (visibility_level in ('standard', 'restricted')),
                reason text not null check (length(btrim(reason)) between 1 and 500),
                granted_by_membership_id uuid not null,
                starts_at timestamptz not null default now(),
                expires_at timestamptz not null,
                revoked_at timestamptz null,
                revoked_by_membership_id uuid null,
                revoked_reason text null check (revoked_reason in ('revoked', 'membership_revoked')),
                created_at timestamptz not null default now(),
                updated_at timestamptz not null default now(),
                lock_version bigint not null default 0,
                constraint grants_organization_id_id_unique unique (organization_id, id),
                constraint grants_target_check check (
                    (scope_type = 'user' and target_membership_id is not null and target_team_id is null and not include_descendants)
                    or (scope_type = 'team' and target_team_id is not null and target_membership_id is null)
                    or (scope_type = 'organization' and target_membership_id is null and target_team_id is null and not include_descendants)
                ),
                constraint grants_not_self_granted check (viewer_membership_id <> granted_by_membership_id),
                constraint grants_not_self_target check (target_membership_id is null or target_membership_id <> viewer_membership_id),
                constraint grants_validity_check check (expires_at > starts_at and expires_at <= starts_at + interval '365 days'),
                constraint grants_revocation_check check (
                    (revoked_at is null and revoked_by_membership_id is null and revoked_reason is null)
                    or (revoked_at is not null and revoked_by_membership_id is not null and revoked_reason is not null)
                ),
                constraint grants_viewer_fk foreign key (organization_id, viewer_membership_id)
                    references identity.organization_memberships (organization_id, id),
                constraint grants_target_membership_fk foreign key (organization_id, target_membership_id)
                    references identity.organization_memberships (organization_id, id),
                constraint grants_target_team_fk foreign key (organization_id, target_team_id)
                    references teams.teams (organization_id, id),
                constraint grants_granted_by_fk foreign key (organization_id, granted_by_membership_id)
                    references identity.organization_memberships (organization_id, id),
                constraint grants_revoked_by_fk foreign key (organization_id, revoked_by_membership_id)
                    references identity.organization_memberships (organization_id, id)
            )
            SQL);
        DB::statement('create index grants_viewer_active_index on monitoring.grants (organization_id, viewer_membership_id) where revoked_at is null');
        DB::statement('create index grants_target_membership_index on monitoring.grants (organization_id, target_membership_id) where revoked_at is null and target_membership_id is not null');
        DB::statement('create index grants_target_team_index on monitoring.grants (organization_id, target_team_id) where revoked_at is null and target_team_id is not null');

        DB::statement(<<<'SQL'
            create table monitoring.grant_events (
                id uuid not null default uuidv7() primary key,
                organization_id uuid not null,
                grant_id uuid null,
                type text not null check (type in ('created', 'updated', 'revoked', 'rejected', 'membership_revoked')),
                actor_membership_id uuid not null,
                reason text null check (reason is null or length(reason) <= 500),
                changes jsonb not null default '{}'::jsonb check (jsonb_typeof(changes) = 'object'),
                occurred_at timestamptz not null default now(),
                constraint grant_events_organization_id_id_unique unique (organization_id, id),
                constraint grant_events_grant_check check ((type = 'rejected') = (grant_id is null)),
                constraint grant_events_grant_fk foreign key (organization_id, grant_id)
                    references monitoring.grants (organization_id, id),
                constraint grant_events_actor_fk foreign key (organization_id, actor_membership_id)
                    references identity.organization_memberships (organization_id, id)
            )
            SQL);
        DB::statement('create index grant_events_grant_index on monitoring.grant_events (organization_id, grant_id, occurred_at, id)');

        foreach (['monitoring.grants', 'monitoring.grant_events'] as $table) {
            $this->applyTenantTemplate($table, $runtime);
        }

        // Runtime: no DELETE; scope, viewer, target and issuer are immutable;
        // history is append-only.
        DB::statement("grant select, insert on table monitoring.grants to {$runtime}");
        DB::statement("grant update (reason, include_descendants, visibility_level, expires_at, revoked_at, revoked_by_membership_id, revoked_reason, updated_at, lock_version) on table monitoring.grants to {$runtime}");
        DB::statement("grant select, insert on table monitoring.grant_events to {$runtime}");
    }

    public function down(): void
    {
        throw new RuntimeException('Monitoring cannot be rolled back; restore from backup instead.');
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
