<?php

namespace Modules\Access\Domain;

use Modules\Access\Contracts\Permission;

/**
 * Anti-escalation rules (D5), pure and unit-tested:
 * - nobody changes their own roles;
 * - the actor needs the required permission (members.manage, or members.invite for invitations);
 * - a role can only be granted/removed if all its permissions are held by the actor;
 * - org_owner can only be granted/removed by an org_owner.
 */
final class RoleAssignmentPolicy
{
    /**
     * @param  list<string>  $actorRoles
     * @param  list<Permission>  $actorPermissions
     * @param  list<Permission>  $rolePermissions
     */
    public static function denialReason(
        bool $isSelf,
        array $actorRoles,
        array $actorPermissions,
        string $roleKey,
        array $rolePermissions,
        Permission $required = Permission::MembersManage,
    ): ?string {
        if ($isSelf) {
            return 'Nobody can change their own roles.';
        }

        if (! in_array($required, $actorPermissions, true)) {
            return "{$required->value} is required.";
        }

        if ($roleKey === RoleTemplates::OWNER && ! in_array(RoleTemplates::OWNER, $actorRoles, true)) {
            return 'Only an org_owner can grant or remove org_owner.';
        }

        foreach ($rolePermissions as $permission) {
            if (! in_array($permission, $actorPermissions, true)) {
                return 'Cannot handle a role with permissions the actor does not hold.';
            }
        }

        return null;
    }
}
