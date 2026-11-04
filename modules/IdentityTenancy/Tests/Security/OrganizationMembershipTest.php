<?php

namespace Modules\IdentityTenancy\Tests\Security;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\IdentityTenancy\Application\CreateOrganization;
use Modules\IdentityTenancy\Contracts\MembershipDirectory;
use Modules\IdentityTenancy\Infrastructure\Persistence\User;
use Modules\Platform\Contracts\Tenancy\OrganizationId;
use Modules\Platform\Contracts\Tenancy\TenantAccessDenied;
use Modules\Platform\Contracts\Tenancy\TenantContext;
use Modules\Platform\Infrastructure\Database\PostgresRoles;
use Modules\Platform\Tests\Security\SecurityTestCase;

/**
 * 01 increment 3 on real PostgreSQL as the runtime role: organization
 * creation, multi-organization membership, revocation and option A.
 */
final class OrganizationMembershipTest extends SecurityTestCase
{
    private TenantContext $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->app->make(TenantContext::class);
    }

    public function test_creator_becomes_active_member_with_server_generated_id_and_event(): void
    {
        $creator = $this->user();
        $organization = $this->app->make(CreateOrganization::class)->handle($creator, 'Acme');

        $this->assertSame('7', $organization->value[14]);

        $this->tenant->runFor($creator, $organization, function () use ($organization, $creator) {
            $this->assertSame('Acme', DB::selectOne('select name from identity.organizations where id = ?', [$organization->value])->name);
            $this->assertSame('active', DB::selectOne('select status from identity.organization_memberships where user_id = ?', [$creator->getKey()])->status);
            $event = DB::selectOne("select event_name, payload::text as payload from events.domain_outbox where aggregate_id = ? and event_name = 'organization.created'", [$organization->value]);
            $this->assertNotNull($event);
            $this->assertStringNotContainsString('Acme', $event->payload, 'Event payloads carry ids only.');
        });
    }

    public function test_user_without_membership_is_denied_and_sees_nothing(): void
    {
        $organization = $this->app->make(CreateOrganization::class)->handle($this->user(), 'Private');
        $outsider = $this->user();

        $this->expectException(TenantAccessDenied::class);

        $this->tenant->runFor($outsider, $organization, fn () => $this->fail('must not run'));
    }

    public function test_member_of_two_organizations_only_sees_the_active_one(): void
    {
        $user = $this->user();
        $a = $this->app->make(CreateOrganization::class)->handle($user, 'Alpha');
        $b = $this->app->make(CreateOrganization::class)->handle($user, 'Beta');

        $this->assertSame(['Alpha'], $this->tenant->runFor($user, $a, fn () => array_column(DB::select('select name from identity.organizations'), 'name')));
        $this->assertSame(['Beta'], $this->tenant->runFor($user, $b, fn () => array_column(DB::select('select name from identity.organizations'), 'name')));
        $this->assertSame(1, $this->tenant->runFor($user, $a, fn () => (int) DB::selectOne('select count(*) as total from identity.organization_memberships')->total));
    }

    public function test_directory_lists_only_active_memberships(): void
    {
        $user = $this->user();
        $kept = $this->app->make(CreateOrganization::class)->handle($user, 'Kept');
        $left = $this->app->make(CreateOrganization::class)->handle($user, 'Left');
        $this->app->make(CreateOrganization::class)->handle($this->user(), 'Not mine');

        $this->revoke($user, $left);

        $ids = array_map(fn ($summary) => $summary->id->value, $this->app->make(MembershipDirectory::class)->organizationsFor($user));
        $this->assertSame([$kept->value], $ids);
    }

    public function test_revoked_membership_is_denied(): void
    {
        $user = $this->user();
        $organization = $this->app->make(CreateOrganization::class)->handle($user, 'Revocable');
        $this->revoke($user, $organization);

        $this->expectException(TenantAccessDenied::class);

        $this->tenant->runFor($user, $organization, fn () => null);
    }

    public function test_tables_are_unreadable_without_tenant_context(): void
    {
        $this->app->make(CreateOrganization::class)->handle($this->user(), 'Hidden');

        $this->assertSame(0, (int) DB::selectOne('select count(*) as total from identity.organizations')->total);
        $this->assertSame(0, (int) DB::selectOne('select count(*) as total from identity.organization_memberships')->total);
    }

    public function test_cannot_add_membership_to_another_organization(): void
    {
        $user = $this->user();
        $a = $this->app->make(CreateOrganization::class)->handle($user, 'Mine');
        $b = $this->app->make(CreateOrganization::class)->handle($this->user(), 'Theirs');

        $this->tenant->runFor($user, $a, function () use ($user, $b) {
            $this->assertSqlState('42501', fn (Connection $connection) => $connection->insert(
                'insert into identity.organization_memberships (organization_id, user_id) values (?, ?)',
                [$b->value, $user->getKey()]
            ));
        });
    }

    public function test_memberships_and_organizations_cannot_be_deleted_or_moved(): void
    {
        $user = $this->user();
        $organization = $this->app->make(CreateOrganization::class)->handle($user, 'Durable');

        $this->tenant->runFor($user, $organization, function () {
            $this->assertSqlState('42501', fn (Connection $connection) => $connection->delete('delete from identity.organization_memberships'));
            $this->assertSqlState('42501', fn (Connection $connection) => $connection->delete('delete from identity.organizations'));
            $this->assertSqlState('42501', fn (Connection $connection) => $connection->update('update identity.organization_memberships set user_id = gen_random_uuid()'));
        });
    }

    public function test_runtime_cannot_assume_the_membership_reader_or_alter_its_functions(): void
    {
        $reader = PostgresRoles::membershipReader();

        $this->assertFalse(DB::selectOne("select pg_has_role(current_user, ?, 'MEMBER') as member", [$reader])->member);
        $this->assertSqlState('42501', fn (Connection $connection) => $connection->statement("set role {$reader}"));
        $this->assertSqlState('42501', fn (Connection $connection) => $connection->statement('alter function identity.has_active_membership(uuid, uuid) security invoker'));
        $this->assertSqlState('42501', fn (Connection $connection) => $connection->statement(
            'create or replace function identity.has_active_membership(p_user_id uuid, p_organization_id uuid) returns boolean language sql as $$ select true $$'
        ));
    }

    public function test_membership_reader_is_nologin_without_elevated_attributes(): void
    {
        $role = DB::selectOne(
            'select rolcanlogin, rolsuper, rolbypassrls, rolcreaterole, rolcreatedb from pg_roles where rolname = ?',
            [PostgresRoles::membershipReader()]
        );

        $this->assertFalse($role->rolcanlogin);
        $this->assertFalse($role->rolsuper);
        $this->assertFalse($role->rolbypassrls);
        $this->assertFalse($role->rolcreaterole);
        $this->assertFalse($role->rolcreatedb);
    }

    private function user(): User
    {
        return User::factory()->create(['email' => Str::uuid().'@example.test']);
    }

    private function revoke(User $user, OrganizationId $organization): void
    {
        $this->tenant->runFor($user, $organization, fn () => DB::update(
            "update identity.organization_memberships set status = 'revoked', revoked_at = now() where user_id = ?",
            [$user->getKey()]
        ));
    }
}
