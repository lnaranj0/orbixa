<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 02 security review (B1): inserting a project member or team link must
 * not race archiving the project or the team. The checks now take FOR SHARE
 * on the target row, so a concurrent archive (an UPDATE of that row) waits
 * for the insert to commit, or the insert waits for the archive and then
 * sees it. SECURITY INVOKER: RLS still applies to both reads.
 *
 * Reading teams.teams from Portfolio mirrors the existing foreign key
 * project_team_links -> teams.teams; Teams does not know about Portfolio.
 *
 * Must run as the owner role (orbixa_migrator).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            create or replace function portfolio.require_open_project() returns trigger
            language plpgsql security invoker
            set search_path = pg_catalog, pg_temp
            as $$
            begin
                perform 1 from portfolio.projects p where p.id = new.project_id and p.status <> 'archived' for share;
                if not found then
                    raise exception 'portfolio: project must not be archived' using errcode = 'check_violation';
                end if;
                return new;
            end
            $$
            SQL);

        DB::statement(<<<'SQL'
            create function portfolio.require_active_linked_team() returns trigger
            language plpgsql security invoker
            set search_path = pg_catalog, pg_temp
            as $$
            begin
                perform 1 from teams.teams t where t.id = new.team_id and t.status = 'active' for share;
                if not found then
                    raise exception 'portfolio: team must be active' using errcode = 'check_violation';
                end if;
                return new;
            end
            $$
            SQL);
        DB::statement('revoke all on function portfolio.require_active_linked_team() from public');
        DB::statement(<<<'SQL'
            create trigger project_team_links_require_active_team
                before insert on portfolio.project_team_links
                for each row execute function portfolio.require_active_linked_team()
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Portfolio triggers cannot be rolled back; roll forward instead.');
    }
};
