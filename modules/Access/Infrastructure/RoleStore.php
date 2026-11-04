<?php

namespace Modules\Access\Infrastructure;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Modules\Access\Contracts\LastOwnerRequired;
use Modules\Access\Contracts\Permission;
use Modules\Access\Domain\RoleTemplates;
use Modules\Platform\Contracts\Tenancy\OrganizationId;

/**
 * Access-owned tables only; every query runs inside the caller's tenant
 * context, so RLS scopes it to the active organization.
 */
final class RoleStore
{
    public function __construct(private readonly DatabaseManager $db) {}

    public function installTemplates(): void
    {
        foreach (RoleTemplates::all() as $key => $template) {
            $roleId = (string) ((array) $this->connection()->selectOne(
                'insert into access.roles (organization_id, key, name, template_version)
                 values (platform.current_organization_id(), ?, ?, ?) returning id::text as id',
                [$key, $template['name'], RoleTemplates::VERSION]
            ))['id'];

            foreach ($template['permissions'] as $permission) {
                $this->connection()->insert(
                    'insert into access.role_permissions (organization_id, role_id, permission)
                     values (platform.current_organization_id(), ?::uuid, ?)',
                    [$roleId, $permission->value]
                );
            }
        }
    }

    public function assign(string $membershipId, string $roleKey, ?string $assignedByMembershipId): void
    {
        $this->connection()->insert(
            'insert into access.membership_roles (organization_id, membership_id, role_id, assigned_by_membership_id)
             select platform.current_organization_id(), ?::uuid, r.id, ?::uuid from access.roles r where r.key = ?
             on conflict do nothing',
            [$membershipId, $assignedByMembershipId, $roleKey]
        );
    }

    public function remove(string $membershipId, string $roleKey): int
    {
        return $this->connection()->delete(
            'delete from access.membership_roles mr using access.roles r
              where mr.role_id = r.id and mr.membership_id = ?::uuid and r.key = ?',
            [$membershipId, $roleKey]
        );
    }

    public function removeAll(string $membershipId): void
    {
        $this->connection()->delete('delete from access.membership_roles where membership_id = ?::uuid', [$membershipId]);
    }

    /**
     * @return list<string>
     */
    public function roleKeysOf(string $membershipId): array
    {
        return array_values(array_map(
            fn (object $row) => (string) ((array) $row)['key'],
            $this->connection()->select(
                'select r.key from access.membership_roles mr join access.roles r on r.id = mr.role_id
                  where mr.membership_id = ?::uuid order by r.key',
                [$membershipId]
            )
        ));
    }

    /**
     * @return list<Permission>
     */
    public function permissionsOf(string $membershipId): array
    {
        return $this->permissions(
            'select distinct rp.permission from access.membership_roles mr
               join access.role_permissions rp on rp.role_id = mr.role_id
              where mr.membership_id = ?::uuid',
            [$membershipId]
        );
    }

    /**
     * @return list<Permission>|null null when the role does not exist
     */
    public function permissionsOfRole(string $roleKey): ?array
    {
        if ($this->connection()->selectOne('select 1 as found from access.roles where key = ?', [$roleKey]) === null) {
            return null;
        }

        return $this->permissions(
            'select rp.permission from access.roles r join access.role_permissions rp on rp.role_id = r.id where r.key = ?',
            [$roleKey]
        );
    }

    public function hasPermission(string $membershipId, Permission $permission): bool
    {
        return $this->connection()->selectOne(
            'select 1 as found from access.membership_roles mr
               join access.role_permissions rp on rp.role_id = mr.role_id
              where mr.membership_id = ?::uuid and rp.permission = ? limit 1',
            [$membershipId, $permission->value]
        ) !== null;
    }

    /**
     * Serializes owner changes per organization (transaction-scoped lock),
     * then refuses if $membershipId is the only remaining org_owner.
     */
    public function assertNotLastOwner(OrganizationId $organization, string $membershipId): void
    {
        $this->connection()->selectOne('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['access.owners:'.$organization->value]);

        $owners = array_map(
            fn (object $row) => (string) ((array) $row)['membership_id'],
            $this->connection()->select(
                'select mr.membership_id::text as membership_id from access.membership_roles mr
                   join access.roles r on r.id = mr.role_id where r.key = ?',
                [RoleTemplates::OWNER]
            )
        );

        if (in_array($membershipId, $owners, true) && count($owners) <= 1) {
            throw LastOwnerRequired::make();
        }
    }

    /**
     * @param  list<string>  $bindings
     * @return list<Permission>
     */
    private function permissions(string $sql, array $bindings): array
    {
        $permissions = [];
        foreach ($this->connection()->select($sql, $bindings) as $row) {
            $permission = Permission::tryFrom((string) ((array) $row)['permission']);
            if ($permission !== null) {
                $permissions[] = $permission;
            }
        }

        return $permissions;
    }

    private function connection(): Connection
    {
        return $this->db->connection();
    }
}
