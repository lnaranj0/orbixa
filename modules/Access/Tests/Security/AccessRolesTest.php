<?php

namespace Modules\Access\Tests\Security;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Modules\Access\Application\ChangeMemberRole;
use Modules\Access\Contracts\AccessDenied;
use Modules\Access\Contracts\Authorizer;
use Modules\Access\Contracts\LastOwnerRequired;
use Modules\Access\Contracts\Permission;
use Modules\IdentityTenancy\Application\CreateOrganization;
use Modules\IdentityTenancy\Contracts\OrganizationLifecycle;
use Modules\IdentityTenancy\Infrastructure\Persistence\User;
use Modules\Platform\Contracts\Tenancy\OrganizationId;
use Modules\Platform\Contracts\Tenancy\TenantContext;
use Modules\Platform\Tests\Security\SecurityTestCase;

/**
 * 01 increment 4 on real PostgreSQL: org_owner on creation, deny-by-default
 * Gate, viewer without permissions, anti-escalation and last owner.
 */
final class AccessRolesTest extends SecurityTestCase
{
    private TenantContext $tenant;

    private User $owner;

    private OrganizationId $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->app->make(TenantContext::class);
        $this->owner = $this->user();
        $this->organization = $this->app->make(CreateOrganization::class)->handle($this->owner, 'Roles Inc');
    }

    public function test_creator_is_org_owner_and_templates_are_installed(): void
    {
        $this->inOrg($this->owner, function () {
            $this->assertSame(7, (int) DB::selectOne('select count(*) as total from access.roles')->total);
            $this->assertSame(['org_owner'], $this->roleKeys($this->owner));
            $this->assertTrue(Gate::forUser($this->owner)->allows(Permission::OrganizationManage->value));
        });
    }

    public function test_gate_denies_outside_a_tenant_context(): void
    {
        $this->assertFalse(Gate::forUser($this->owner)->allows(Permission::OrganizationManage->value));
        $this->assertFalse(Gate::forUser($this->owner)->allows('undefined.ability'));
    }

    public function test_viewer_has_no_domain_permission(): void
    {
        $viewer = $this->member();
        $this->inOrg($this->owner, fn () => $this->roles()->grant($this->owner, $this->membershipId($viewer), 'viewer'));

        $this->inOrg($viewer, function () use ($viewer) {
            foreach (Permission::cases() as $permission) {
                $this->assertFalse($this->app->make(Authorizer::class)->can($viewer, $permission), "viewer must not hold {$permission->value}");
                $this->assertFalse(Gate::forUser($viewer)->allows($permission->value));
            }
        });
    }

    public function test_admin_cannot_grant_owner_or_change_own_roles(): void
    {
        $admin = $this->member();
        $other = $this->member();
        $this->inOrg($this->owner, fn () => $this->roles()->grant($this->owner, $this->membershipId($admin), 'org_admin'));

        $this->inOrg($admin, function () use ($admin, $other) {
            $this->assertDeniedAccess(fn () => $this->roles()->grant($admin, $this->membershipId($other), 'org_owner'));
            $this->assertDeniedAccess(fn () => $this->roles()->grant($admin, $this->membershipId($admin), 'org_owner'));
            $this->assertDeniedAccess(fn () => $this->roles()->remove($admin, $this->membershipId($admin), 'org_admin'));

            $this->roles()->grant($admin, $this->membershipId($other), 'developer');
            $this->assertSame(['developer'], $this->roleKeys($other));
        });
    }

    public function test_member_without_members_manage_cannot_grant_roles(): void
    {
        $developer = $this->member();
        $other = $this->member();
        $this->inOrg($this->owner, fn () => $this->roles()->grant($this->owner, $this->membershipId($developer), 'developer'));

        $this->inOrg($developer, fn () => $this->assertDeniedAccess(
            fn () => $this->roles()->grant($developer, $this->membershipId($other), 'viewer')
        ));
    }

    public function test_last_owner_cannot_be_removed_but_a_co_owner_can(): void
    {
        $coOwner = $this->member();
        $this->inOrg($this->owner, function () use ($coOwner) {
            $this->roles()->grant($this->owner, $this->membershipId($coOwner), 'org_owner');
            $this->roles()->remove($this->owner, $this->membershipId($coOwner), 'org_owner');
            $this->assertSame([], $this->roleKeys($coOwner));

            $hook = $this->accessLifecycle();
            $this->expectException(LastOwnerRequired::class);
            $hook->membershipRevoking($this->organization, $this->membershipId($this->owner));
        });
    }

    public function test_revoking_hook_removes_roles_of_a_non_last_owner(): void
    {
        $coOwner = $this->member();
        $this->inOrg($this->owner, function () use ($coOwner) {
            $this->roles()->grant($this->owner, $this->membershipId($coOwner), 'org_owner');
            $this->accessLifecycle()->membershipRevoking($this->organization, $this->membershipId($coOwner));
            $this->assertSame([], $this->roleKeys($coOwner));
            $this->assertSame(['org_owner'], $this->roleKeys($this->owner));
        });
    }

    public function test_role_change_event_carries_ids_only(): void
    {
        $member = $this->member();
        $this->inOrg($this->owner, function () use ($member) {
            $this->roles()->grant($this->owner, $this->membershipId($member), 'developer');
            $event = DB::selectOne("select payload::text as payload from events.domain_outbox where event_name = 'organization.member.role_changed' and aggregate_id = ?", [$this->membershipId($member)]);
            $this->assertNotNull($event);
            $this->assertStringNotContainsString((string) $member->email, $event->payload);
        });
    }

    public function test_roles_are_isolated_and_cannot_reference_another_organization(): void
    {
        $otherOwner = $this->user();
        $other = $this->app->make(CreateOrganization::class)->handle($otherOwner, 'Other Inc');
        $foreignMembership = $this->inOrg($otherOwner, fn () => $this->membershipId($otherOwner), $other);
        $foreignRole = $this->inOrg($otherOwner, fn () => (string) DB::selectOne("select id::text as id from access.roles where key = 'viewer'")->id, $other);

        $this->inOrg($this->owner, function () use ($foreignMembership, $foreignRole, $other) {
            $this->assertSame(0, (int) DB::selectOne('select count(*) as total from access.roles where id = ?', [$foreignRole])->total);
            // Composite FKs: a role holding cannot point at another organization's membership.
            $this->assertSqlState('23503', fn (Connection $connection) => $connection->insert(
                "insert into access.membership_roles (organization_id, membership_id, role_id)
                 select platform.current_organization_id(), ?::uuid, id from access.roles where key = 'viewer'",
                [$foreignMembership]
            ));
            $this->assertSqlState('42501', fn (Connection $connection) => $connection->insert(
                "insert into access.role_permissions (organization_id, role_id, permission) values (?, ?, 'work.create')",
                [$other->value, $foreignRole]
            ));
            $this->assertSqlState('42501', fn (Connection $connection) => $connection->update("update access.roles set name = 'x'"));
            $this->assertSqlState('42501', fn (Connection $connection) => $connection->delete('delete from access.roles'));
        });
    }

    private function roles(): ChangeMemberRole
    {
        return $this->app->make(ChangeMemberRole::class);
    }

    private function accessLifecycle(): OrganizationLifecycle
    {
        $hooks = iterator_to_array($this->app->tagged(OrganizationLifecycle::TAG));
        $this->assertCount(1, $hooks);

        return $hooks[0];
    }

    private function assertDeniedAccess(\Closure $operation): void
    {
        try {
            DB::transaction($operation);
            $this->fail('Expected AccessDenied.');
        } catch (AccessDenied) {
            $this->addToAssertionCount(1);
        }
    }

    private function inOrg(User $actor, \Closure $callback, ?OrganizationId $organization = null): mixed
    {
        return $this->tenant->runFor($actor, $organization ?? $this->organization, $callback);
    }

    private function user(): User
    {
        return User::factory()->create(['email' => Str::uuid().'@example.test']);
    }

    /** Adds an active membership directly (invitations arrive in increment 5). */
    private function member(): User
    {
        $user = $this->user();
        $this->inOrg($this->owner, fn () => DB::insert(
            'insert into identity.organization_memberships (organization_id, user_id) values (platform.current_organization_id(), ?)',
            [$user->getKey()]
        ));

        return $user;
    }

    private function membershipId(User $user): string
    {
        return (string) DB::selectOne('select id::text as id from identity.organization_memberships where user_id = ?', [$user->getKey()])->id;
    }

    /**
     * @return list<string>
     */
    private function roleKeys(User $user): array
    {
        return array_column(DB::select(
            'select r.key from access.membership_roles mr join access.roles r on r.id = mr.role_id
              where mr.membership_id = ? order by r.key',
            [$this->membershipId($user)]
        ), 'key');
    }
}
