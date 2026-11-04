<?php

namespace Modules\Access\Application;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Access\Contracts\AccessDenied;
use Modules\Access\Domain\RoleAssignmentPolicy;
use Modules\Access\Domain\RoleTemplates;
use Modules\Access\Infrastructure\RoleStore;
use Modules\IdentityTenancy\Contracts\CurrentMembership;
use Modules\Platform\Contracts\Events\ActorReference;
use Modules\Platform\Contracts\Events\DomainEvent;
use Modules\Platform\Contracts\Events\DomainEventRecorder;
use Modules\Platform\Contracts\Tenancy\TenantContext;
use Modules\Platform\Contracts\Tenancy\TenantContextRequired;

/**
 * Grants or removes a role on another member of the ACTIVE organization,
 * enforcing RoleAssignmentPolicy and the last-owner invariant, and records
 * organization.member.role_changed (ids only).
 */
final class ChangeMemberRole
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly CurrentMembership $memberships,
        private readonly RoleStore $roles,
        private readonly DomainEventRecorder $events,
    ) {}

    public function grant(Authenticatable $actor, string $targetMembershipId, string $roleKey): void
    {
        $this->change($actor, $targetMembershipId, $roleKey, granted: true);
    }

    public function remove(Authenticatable $actor, string $targetMembershipId, string $roleKey): void
    {
        $this->change($actor, $targetMembershipId, $roleKey, granted: false);
    }

    private function change(Authenticatable $actor, string $targetMembershipId, string $roleKey, bool $granted): void
    {
        $organization = $this->tenant->current() ?? throw TenantContextRequired::forOperation('Changing member roles');
        $actorMembershipId = $this->memberships->idFor($actor) ?? throw AccessDenied::because('No active membership.');

        $rolePermissions = $this->roles->permissionsOfRole($roleKey) ?? throw AccessDenied::because('Unknown role.');

        $reason = RoleAssignmentPolicy::denialReason(
            isSelf: $actorMembershipId === $targetMembershipId,
            actorRoles: $this->roles->roleKeysOf($actorMembershipId),
            actorPermissions: $this->roles->permissionsOf($actorMembershipId),
            roleKey: $roleKey,
            rolePermissions: $rolePermissions,
        );

        if ($reason !== null) {
            throw AccessDenied::because($reason);
        }

        if ($granted) {
            $this->roles->assign($targetMembershipId, $roleKey, $actorMembershipId);
        } else {
            if ($roleKey === RoleTemplates::OWNER) {
                $this->roles->assertNotLastOwner($organization, $targetMembershipId);
            }
            $this->roles->remove($targetMembershipId, $roleKey);
        }

        $this->events->record(
            new DomainEvent('organization.member.role_changed', 1, 'membership', $targetMembershipId, [
                'role_key' => $roleKey,
                'change' => $granted ? 'granted' : 'removed',
                'changed_by_membership_id' => $actorMembershipId,
            ]),
            ActorReference::human((string) $actor->getAuthIdentifier()),
        );
    }
}
