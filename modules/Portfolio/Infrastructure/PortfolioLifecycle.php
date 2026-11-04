<?php

namespace Modules\Portfolio\Infrastructure;

use Modules\IdentityTenancy\Contracts\OrganizationLifecycle;
use Modules\Platform\Contracts\Tenancy\OrganizationId;

/** D8: revoking an organization membership closes its project memberships. */
final class PortfolioLifecycle implements OrganizationLifecycle
{
    public function __construct(private readonly ProjectStore $projects) {}

    public function organizationCreated(OrganizationId $organization, string $creatorMembershipId): void {}

    public function membershipJoined(OrganizationId $organization, string $membershipId, string $roleKey, string $invitedByMembershipId): void {}

    public function membershipRevoking(OrganizationId $organization, string $membershipId, string $revokedByMembershipId): void
    {
        $this->projects->closeAllForMembership($membershipId, $revokedByMembershipId);
    }
}
