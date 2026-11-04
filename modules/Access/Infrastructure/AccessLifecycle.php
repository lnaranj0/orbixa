<?php

namespace Modules\Access\Infrastructure;

use Modules\Access\Contracts\AccessDenied;
use Modules\Access\Contracts\Permission;
use Modules\Access\Domain\RoleAssignmentPolicy;
use Modules\Access\Domain\RoleTemplates;
use Modules\IdentityTenancy\Contracts\OrganizationLifecycle;
use Modules\Platform\Contracts\Tenancy\OrganizationId;

/**
 * Reacts to IdentityTenancy lifecycle hooks inside the same transaction.
 * Invariant: only active memberships hold roles, so owners and permissions
 * can be evaluated from Access tables alone.
 */
final class AccessLifecycle implements OrganizationLifecycle
{
    public function __construct(private readonly RoleStore $roles) {}

    public function organizationCreated(OrganizationId $organization, string $creatorMembershipId): void
    {
        $this->roles->installTemplates();
        $this->roles->assign($creatorMembershipId, RoleTemplates::OWNER, null);
    }

    public function membershipJoined(OrganizationId $organization, string $membershipId, string $roleKey, string $invitedByMembershipId): void
    {
        // Re-check at acceptance: the inviter may have lost the permission or
        // been revoked since the invitation was issued.
        $rolePermissions = $this->roles->permissionsOfRole($roleKey) ?? throw AccessDenied::because('Unknown role.');

        $reason = RoleAssignmentPolicy::denialReason(
            isSelf: false,
            actorRoles: $this->roles->roleKeysOf($invitedByMembershipId),
            actorPermissions: $this->roles->permissionsOf($invitedByMembershipId),
            roleKey: $roleKey,
            rolePermissions: $rolePermissions,
            required: Permission::MembersInvite,
        );

        if ($reason !== null) {
            throw AccessDenied::because('The inviter can no longer grant this role.');
        }

        $this->roles->assign($membershipId, $roleKey, $invitedByMembershipId);
    }

    public function membershipRevoking(OrganizationId $organization, string $membershipId, string $revokedByMembershipId): void
    {
        if (in_array(RoleTemplates::OWNER, $this->roles->roleKeysOf($membershipId), true)
            && ! in_array(RoleTemplates::OWNER, $this->roles->roleKeysOf($revokedByMembershipId), true)) {
            throw AccessDenied::because('Only an org_owner can revoke an org_owner.');
        }

        $this->roles->assertNotLastOwner($organization, $membershipId);
        $this->roles->removeAll($membershipId);
    }
}
