<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 06-github-integration (06a) closing review (postgres-reviewer):
 *
 * - repository_project_links is temporal history: once unlinked, a row can
 *   never be revived or have its unlink rewritten (the runtime holds UPDATE
 *   on the unlink columns), and it cannot be unlinked before it was linked.
 * - A purged inbox payload is the empty object.
 * - Indexes for the queries in use or announced: delivery register by age
 *   (retention, 06b), inbox rows whose payload awaits purge, links of a
 *   project, and the repository overview ordered by name.
 *
 * Must run as the owner role (orbixa_migrator). Tables are still empty
 * outside development, so plain CREATE INDEX is fine.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            create function integrations.prevent_link_reopen() returns trigger
            language plpgsql
            set search_path = pg_catalog, pg_temp
            as $$
            begin
                if old.unlinked_at is not null or new.unlinked_at is null then
                    raise exception 'repository_project_links rows are unlinked once and never reopened'
                        using errcode = 'check_violation';
                end if;
                return new;
            end
            $$
            SQL);
        DB::statement('revoke all on function integrations.prevent_link_reopen() from public');
        DB::statement(<<<'SQL'
            create trigger repository_project_links_unlink_once
                before update of unlinked_at, unlinked_by_membership_id on integrations.repository_project_links
                for each row execute function integrations.prevent_link_reopen()
            SQL);
        DB::statement('alter table integrations.repository_project_links add constraint repository_project_links_unlink_after_link check (unlinked_at is null or unlinked_at >= linked_at)');

        DB::statement("alter table integrations.webhook_inbox add constraint webhook_inbox_purged_payload_check check (payload_purged_at is null or payload = '{}'::jsonb)");

        DB::statement('create index webhook_deliveries_received_index on integrations.webhook_deliveries (received_at)');
        DB::statement('create index webhook_inbox_unpurged_index on integrations.webhook_inbox (organization_id, received_at) where payload_purged_at is null');
        DB::statement('create index repository_project_links_project_index on integrations.repository_project_links (organization_id, project_id) where unlinked_at is null');
        DB::statement('create index github_repositories_name_index on integrations.github_repositories (organization_id, full_name, id)');
    }

    public function down(): void
    {
        throw new RuntimeException('The integrations hardening cannot be rolled back; roll forward instead.');
    }
};
