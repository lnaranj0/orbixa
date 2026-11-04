<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Platform\Infrastructure\Database\PostgresRoles;

/**
 * 01-identity-tenancy, increment 5: invitations (decision D8).
 *
 * - The secret token is never stored: only sha256(token) as 32 bytes.
 * - Single use, 7-day expiry, bound to the invitee's normalized email,
 *   revocable; acceptance is an atomic UPDATE ... WHERE still-pending.
 * - The invitee is not yet a member, so the admission check uses a third
 *   narrow SECURITY DEFINER function (option A),
 *   identity.admissible_invitation(token_hash, organization, email) -> boolean,
 *   owned by orbixa_membership_reader like the other two.
 *
 * Must run as the owner role (orbixa_migrator).
 */
return new class extends Migration
{
    public function up(): void
    {
        $runtime = PostgresRoles::runtime();
        $reader = PostgresRoles::membershipReader();

        DB::statement(<<<'SQL'
            create table identity.invitations (
                id uuid not null default uuidv7() primary key,
                organization_id uuid not null references identity.organizations (id),
                email_normalized text not null
                    check (email_normalized = lower(btrim(email_normalized))
                           and length(email_normalized) between 3 and 254
                           and position('@' in email_normalized) > 1),
                role_key text not null check (role_key ~ '^[a-z][a-z0-9_]{1,49}$'),
                token_hash bytea not null check (octet_length(token_hash) = 32),
                invited_by_membership_id uuid not null,
                created_at timestamptz not null,
                expires_at timestamptz not null,
                accepted_at timestamptz null,
                accepted_membership_id uuid null,
                revoked_at timestamptz null,
                revoked_by_membership_id uuid null,
                constraint invitations_organization_id_id_unique unique (organization_id, id),
                constraint invitations_token_hash_unique unique (token_hash),
                constraint invitations_expiry_check check (expires_at > created_at and expires_at <= created_at + interval '7 days'),
                constraint invitations_final_state_check check (not (accepted_at is not null and revoked_at is not null)),
                constraint invitations_accepted_check check ((accepted_at is null) = (accepted_membership_id is null)),
                constraint invitations_invited_by_fk foreign key (organization_id, invited_by_membership_id)
                    references identity.organization_memberships (organization_id, id),
                constraint invitations_accepted_membership_fk foreign key (organization_id, accepted_membership_id)
                    references identity.organization_memberships (organization_id, id),
                constraint invitations_revoked_by_fk foreign key (organization_id, revoked_by_membership_id)
                    references identity.organization_memberships (organization_id, id)
            )
            SQL);
        DB::statement(<<<'SQL'
            create unique index invitations_one_pending_per_email
                on identity.invitations (organization_id, email_normalized)
                where accepted_at is null and revoked_at is null
            SQL);

        DB::statement('revoke all on table identity.invitations from public');
        DB::statement('alter table identity.invitations enable row level security');
        DB::statement('alter table identity.invitations force row level security');
        DB::statement(<<<SQL
            create policy tenant_isolation on identity.invitations
                as permissive for all to {$runtime}
                using (organization_id = (select platform.current_organization_id()))
                with check (organization_id = (select platform.current_organization_id()))
            SQL);
        DB::statement(<<<'SQL'
            create trigger identity_invitations_organization_id_immutable
                before update of organization_id on identity.invitations
                for each row when (old.organization_id is distinct from new.organization_id)
                execute function platform.prevent_organization_id_change()
            SQL);

        // Runtime: issue, read and close invitations; never delete, never edit token, email, role or expiry.
        DB::statement("grant select, insert on table identity.invitations to {$runtime}");
        DB::statement("grant update (accepted_at, accepted_membership_id, revoked_at, revoked_by_membership_id) on table identity.invitations to {$runtime}");

        // Reader: only what the admission function needs.
        DB::statement("grant select (organization_id, email_normalized, token_hash, expires_at, accepted_at, revoked_at) on table identity.invitations to {$reader}");
        DB::statement("create policy membership_reader_read on identity.invitations as permissive for select to {$reader} using (true)");

        DB::statement(<<<'SQL'
            create function identity.admissible_invitation(p_token_hash bytea, p_organization_id uuid, p_email text) returns boolean
            language sql stable security definer
            set search_path = pg_catalog, pg_temp
            as $$
                select exists (
                    select 1
                      from identity.invitations i
                     where i.token_hash = p_token_hash
                       and i.organization_id = p_organization_id
                       and i.email_normalized = lower(btrim(p_email))
                       and i.accepted_at is null
                       and i.revoked_at is null
                       and i.expires_at > now()
                )
            $$
            SQL);

        // Privileges first, ownership last (see 2026_11_04_000200).
        DB::statement('revoke all on function identity.admissible_invitation(bytea, uuid, text) from public');
        DB::statement("grant execute on function identity.admissible_invitation(bytea, uuid, text) to {$runtime}");
        DB::statement("alter function identity.admissible_invitation(bytea, uuid, text) owner to {$reader}");
    }

    public function down(): void
    {
        throw new RuntimeException('Invitations cannot be rolled back; restore from backup instead.');
    }
};
