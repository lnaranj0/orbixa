<?php

namespace Modules\Access\Infrastructure;

use Modules\Access\Domain\RoleTemplates;
use Modules\IdentityTenancy\Contracts\OrganizationLifecycle;
use Modules\Platform\Contracts\Tenancy\OrganizationId;

/**
 * Reacts to IdentityTenancy lifecycle hooks inside the same transaction.
 */
final class AccessLifecycle implements OrganizationLifecycle
{
    public function __construct(private readonly RoleStore $roles) {}

    public function organizationCreated(OrganizationId $organization, string $creatorMembershipId): void
    {
        $this->roles->installTemplates();
        $this->roles->assign($creatorMembershipId, RoleTemplates::OWNER, null);
    }

    public function membershipRevoking(OrganizationId $organization, string $membershipId): void
    {
        // Invariant: only active memberships hold roles, so owners can be
        // counted from Access tables alone.
        $this->roles->assertNotLastOwner($organization, $membershipId);
        $this->roles->removeAll($membershipId);
    }
}
