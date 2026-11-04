<?php

namespace Modules\Teams\Infrastructure;

use Modules\IdentityTenancy\Contracts\OrganizationLifecycle;
use Modules\Platform\Contracts\Tenancy\OrganizationId;

/**
 * D8: revoking an organization membership closes its team memberships in
 * the same transaction, keeping them as history.
 */
final class TeamsLifecycle implements OrganizationLifecycle
{
    public function __construct(private readonly TeamStore $teams) {}

    public function organizationCreated(OrganizationId $organization, string $creatorMembershipId): void {}

    public function membershipJoined(OrganizationId $organization, string $membershipId, string $roleKey, string $invitedByMembershipId): void {}

    public function membershipRevoking(OrganizationId $organization, string $membershipId, string $revokedByMembershipId): void
    {
        $this->teams->closeAllForMembership($membershipId, $revokedByMembershipId);
    }
}
