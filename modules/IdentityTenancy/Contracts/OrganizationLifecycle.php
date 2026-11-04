<?php

namespace Modules\IdentityTenancy\Contracts;

use Modules\Platform\Contracts\Tenancy\OrganizationId;

/**
 * Synchronous hooks IdentityTenancy invokes inside its own transactions, so
 * other modules (Access) react without IdentityTenancy depending on them.
 * Throwing from a hook rolls the whole operation back.
 */
interface OrganizationLifecycle
{
    public const TAG = 'orbixa.organization-lifecycle';

    /** Called after the organization and the creator's membership exist. */
    public function organizationCreated(OrganizationId $organization, string $creatorMembershipId): void;

    /** Called before a membership is revoked; may veto (e.g. last owner). */
    public function membershipRevoking(OrganizationId $organization, string $membershipId): void;
}
