<?php

namespace Modules\Access\Infrastructure;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Access\Contracts\Permission;
use Modules\Access\Domain\RoleAssignmentPolicy;
use Modules\IdentityTenancy\Contracts\CurrentMembership;
use Modules\IdentityTenancy\Contracts\RoleGrantGuard;

final class AccessRoleGrantGuard implements RoleGrantGuard
{
    public function __construct(
        private readonly CurrentMembership $memberships,
        private readonly RoleStore $roles,
    ) {}

    public function canInvite(Authenticatable $inviter, string $roleKey): bool
    {
        $membershipId = $this->memberships->idFor($inviter);
        $rolePermissions = $this->roles->permissionsOfRole($roleKey);

        if ($membershipId === null || $rolePermissions === null) {
            return false;
        }

        return RoleAssignmentPolicy::denialReason(
            isSelf: false,
            actorRoles: $this->roles->roleKeysOf($membershipId),
            actorPermissions: $this->roles->permissionsOf($membershipId),
            roleKey: $roleKey,
            rolePermissions: $rolePermissions,
            required: Permission::MembersInvite,
        ) === null;
    }
}
