<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Platform\Infrastructure\Database\PostgresRoles;

/**
 * 06-github-integration (06a), increment 2: integrations schema (G1, G3, G-C, G-D).
 *
 * Tenant tables (RLS template):
 * - github_installations: one GitHub App installation per organization;
 *   installation_external_id is GLOBALLY unique (an installation belongs to
 *   exactly one organization). Status active|suspended|revoked.
 * - github_repositories: repositories of an installation, identified by
 *   GitHub's numeric id; only explicitly selected ones are indexed.
 * - repository_project_links: which projects a repository serves
 *   (temporal rows); owned here, not by Portfolio (G-D).
 * - webhook_inbox: routed webhook payloads of the organization, retained
 *   30 days (payload purged, row kept).
 *
 * Global table (reviewed allowlist, no tenant data):
 * - webhook_deliveries: dedup register only; delivery id and body hash, no
 *   payload and no organization.
 *
 * Must run as the owner role (orbixa_migrator), after Portfolio.
 */
return new class extends Migration
{
    private const TENANT_TABLES = [
        'integrations.github_installations',
        'integrations.github_repositories',
        'integrations.repository_project_links',
        'integrations.webhook_inbox',
    ];

    public function up(): void
    {
        $runtime = PostgresRoles::runtime();

        DB::statement('create schema integrations');
        DB::statement('revoke all on schema integrations from public');
        DB::statement("grant usage on schema integrations to {$runtime}");

        DB::statement(<<<'SQL'
            create table integrations.github_installations (
                id uuid not null default uuidv7() primary key,
                organization_id uuid not null references identity.organizations (id),
                installation_external_id bigint not null check (installation_external_id > 0),
                account_login text not null check (length(account_login) between 1 and 100),
                account_type text not null check (account_type in ('User', 'Organization')),
                status text not null default 'active' check (status in ('active', 'suspended', 'revoked')),
                linked_by_membership_id uuid not null,
                linked_at timestamptz not null default now(),
                status_changed_at timestamptz null,
                updated_at timestamptz not null default now(),
                lock_version bigint not null default 0,
                constraint github_installations_organization_id_id_unique unique (organization_id, id),
                constraint github_installations_external_unique unique (installation_external_id),
                constraint github_installations_linked_by_fk foreign key (organization_id, linked_by_membership_id)
                    references identity.organization_memberships (organization_id, id)
            )
            SQL);

        DB::statement(<<<'SQL'
            create table integrations.github_repositories (
                id uuid not null default uuidv7() primary key,
                organization_id uuid not null,
                installation_id uuid not null,
                repository_external_id bigint not null check (repository_external_id > 0),
                full_name text not null check (length(full_name) between 3 and 200),
                is_private boolean not null,
                available boolean not null default true,
                selected_at timestamptz null,
                selected_by_membership_id uuid null,
                updated_at timestamptz not null default now(),
                constraint github_repositories_organization_id_id_unique unique (organization_id, id),
                constraint github_repositories_external_unique unique (organization_id, installation_id, repository_external_id),
                constraint github_repositories_selection_check check ((selected_at is null) = (selected_by_membership_id is null)),
                constraint github_repositories_installation_fk foreign key (organization_id, installation_id)
                    references integrations.github_installations (organization_id, id),
                constraint github_repositories_selected_by_fk foreign key (organization_id, selected_by_membership_id)
                    references identity.organization_memberships (organization_id, id)
            )
            SQL);
        DB::statement('create index github_repositories_selected_index on integrations.github_repositories (organization_id, repository_external_id) where selected_at is not null');

        DB::statement(<<<'SQL'
            create table integrations.repository_project_links (
                id uuid not null default uuidv7() primary key,
                organization_id uuid not null,
                repository_id uuid not null,
                project_id uuid not null,
                linked_at timestamptz not null default now(),
                linked_by_membership_id uuid not null,
                unlinked_at timestamptz null,
                unlinked_by_membership_id uuid null,
                constraint repository_project_links_organization_id_id_unique unique (organization_id, id),
                constraint repository_project_links_unlink_check check ((unlinked_at is null) = (unlinked_by_membership_id is null)),
                constraint repository_project_links_repository_fk foreign key (organization_id, repository_id)
                    references integrations.github_repositories (organization_id, id),
                constraint repository_project_links_project_fk foreign key (organization_id, project_id)
                    references portfolio.projects (organization_id, id),
                constraint repository_project_links_linked_by_fk foreign key (organization_id, linked_by_membership_id)
                    references identity.organization_memberships (organization_id, id),
                constraint repository_project_links_unlinked_by_fk foreign key (organization_id, unlinked_by_membership_id)
                    references identity.organization_memberships (organization_id, id)
            )
            SQL);
        DB::statement('create unique index repository_project_links_one_active on integrations.repository_project_links (organization_id, repository_id, project_id) where unlinked_at is null');

        DB::statement(<<<'SQL'
            create table integrations.webhook_inbox (
                id uuid not null default uuidv7() primary key,
                organization_id uuid not null,
                installation_id uuid not null,
                repository_id uuid null,
                delivery_id text not null check (delivery_id ~ '^[A-Za-z0-9-]{1,64}$'),
                event text not null check (event ~ '^[a-z_]{1,64}$'),
                action text null check (action is null or action ~ '^[a-z_]{1,64}$'),
                payload jsonb not null check (jsonb_typeof(payload) = 'object'),
                payload_purged_at timestamptz null,
                status text not null default 'pending' check (status in ('pending', 'processed', 'failed')),
                attempts integer not null default 0 check (attempts >= 0),
                error_code text null check (error_code is null or length(error_code) <= 200),
                received_at timestamptz not null default now(),
                processed_at timestamptz null,
                constraint webhook_inbox_organization_id_id_unique unique (organization_id, id),
                constraint webhook_inbox_delivery_unique unique (organization_id, delivery_id),
                constraint webhook_inbox_installation_fk foreign key (organization_id, installation_id)
                    references integrations.github_installations (organization_id, id),
                constraint webhook_inbox_repository_fk foreign key (organization_id, repository_id)
                    references integrations.github_repositories (organization_id, id)
            )
            SQL);
        DB::statement("create index webhook_inbox_pending_index on integrations.webhook_inbox (organization_id, received_at) where status = 'pending'");

        DB::statement(<<<'SQL'
            create table integrations.webhook_deliveries (
                delivery_id text not null primary key check (delivery_id ~ '^[A-Za-z0-9-]{1,64}$'),
                body_sha256 bytea not null check (length(body_sha256) = 32),
                event text not null check (event ~ '^[a-z_]{1,64}$'),
                installation_external_id bigint null,
                outcome text not null check (outcome in ('accepted', 'ignored')),
                received_at timestamptz not null default now(),
                constraint webhook_deliveries_body_unique unique (body_sha256)
            )
            SQL);

        foreach (self::TENANT_TABLES as $table) {
            $this->applyTenantTemplate($table, $runtime);
        }

        // Runtime: no DELETE anywhere; identities and payload keys are immutable.
        DB::statement("grant select, insert on table integrations.github_installations to {$runtime}");
        DB::statement("grant update (account_login, status, status_changed_at, updated_at, lock_version) on table integrations.github_installations to {$runtime}");
        DB::statement("grant select, insert on table integrations.github_repositories to {$runtime}");
        DB::statement("grant update (full_name, is_private, available, selected_at, selected_by_membership_id, updated_at) on table integrations.github_repositories to {$runtime}");
        DB::statement("grant select, insert on table integrations.repository_project_links to {$runtime}");
        DB::statement("grant update (unlinked_at, unlinked_by_membership_id) on table integrations.repository_project_links to {$runtime}");
        DB::statement("grant select, insert on table integrations.webhook_inbox to {$runtime}");
        DB::statement("grant update (payload, payload_purged_at, status, attempts, error_code, processed_at) on table integrations.webhook_inbox to {$runtime}");
        // Global dedup register: insert and look up only.
        DB::statement('revoke all on table integrations.webhook_deliveries from public');
        DB::statement("grant select, insert on table integrations.webhook_deliveries to {$runtime}");
    }

    public function down(): void
    {
        throw new RuntimeException('Integrations cannot be rolled back; restore from backup instead.');
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
