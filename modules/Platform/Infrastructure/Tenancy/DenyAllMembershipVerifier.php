<?php

namespace Modules\Platform\Infrastructure\Tenancy;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Platform\Contracts\Tenancy\MembershipVerifier;
use Modules\Platform\Contracts\Tenancy\OrganizationId;

/**
 * Fail-closed default until 01-identity-tenancy provides memberships.
 */
final class DenyAllMembershipVerifier implements MembershipVerifier
{
    public function hasActiveMembership(Authenticatable $actor, OrganizationId $organization): bool
    {
        return false;
    }
}
