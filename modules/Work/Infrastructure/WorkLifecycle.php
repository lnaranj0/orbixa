<?php

namespace Modules\Work\Infrastructure;

use Modules\IdentityTenancy\Contracts\OrganizationLifecycle;
use Modules\Platform\Contracts\Tenancy\OrganizationId;

/** W7: revoking an organization membership closes its work assignments. */
final class WorkLifecycle implements OrganizationLifecycle
{
    public function __construct(private readonly ItemStore $items) {}

    public function organizationCreated(OrganizationId $organization, string $creatorMembershipId): void {}

    public function membershipJoined(OrganizationId $organization, string $membershipId, string $roleKey, string $invitedByMembershipId): void {}

    public function membershipRevoking(OrganizationId $organization, string $membershipId, string $revokedByMembershipId): void
    {
        $this->items->closeAllAssignmentsOf($membershipId, $revokedByMembershipId);
    }
}
