<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Platform\Infrastructure\Database\PostgresRoles;

/**
 * 01-identity-tenancy, D2: IdentityTenancy takes ownership of the global
 * identity tables and switches user ids to uuid (ADR-004).
 *
 * - public.users and public.password_reset_tokens move to schema identity.
 * - identity.users.id: bigint + sequence -> uuid default uuidv7().
 * - public.sessions.user_id: bigint -> uuid.
 *
 * Roll-forward only and guarded: it aborts unless both tables are empty and
 * no session references a user, because converting existing ids would break
 * every reference to them. Must run as the owner role (orbixa_migrator).
 */
return new class extends Migration
{
    public function up(): void
    {
        $runtime = PostgresRoles::runtime();

        DB::statement(<<<'SQL'
            do $$
            begin
                if exists (select 1 from public.users)
                   or exists (select 1 from public.password_reset_tokens)
                   or exists (select 1 from public.sessions where user_id is not null) then
                    raise exception 'identity migration guard: users, password_reset_tokens and authenticated sessions must be empty';
                end if;
            end
            $$
            SQL);

        DB::statement('create schema identity');
        DB::statement('revoke all on schema identity from public');
        DB::statement("grant usage on schema identity to {$runtime}");

        // SET SCHEMA also moves indexes, constraints and owned sequences.
        DB::statement('alter table public.users set schema identity');
        DB::statement('alter table public.password_reset_tokens set schema identity');

        DB::statement('alter table identity.users alter column id drop default');
        DB::statement('alter sequence identity.users_id_seq owned by none');
        DB::statement('drop sequence identity.users_id_seq');
        DB::statement('alter table identity.users alter column id type uuid using null::uuid');
        DB::statement('alter table identity.users alter column id set default uuidv7()');

        DB::statement('alter table public.sessions alter column user_id type uuid using null::uuid');

        // Re-assert the reviewed runtime privileges on the moved tables.
        DB::statement("revoke all on table identity.users from public, {$runtime}");
        DB::statement("grant select, insert on table identity.users to {$runtime}");
        DB::statement("grant update (name, email, email_verified_at, password, remember_token, updated_at) on table identity.users to {$runtime}");
        DB::statement("revoke all on table identity.password_reset_tokens from public, {$runtime}");
        DB::statement("grant select, insert, delete on table identity.password_reset_tokens to {$runtime}");
    }

    public function down(): void
    {
        throw new RuntimeException('Moving users back to bigint ids is not supported; restore from backup instead.');
    }
};
