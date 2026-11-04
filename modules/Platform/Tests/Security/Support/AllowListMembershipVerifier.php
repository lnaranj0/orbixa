<?php

namespace Modules\Platform\Tests\Security\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Platform\Contracts\Tenancy\MembershipVerifier;
use Modules\Platform\Contracts\Tenancy\OrganizationId;

/**
 * Test double standing in for the 01-identity-tenancy membership check.
 */
final class AllowListMembershipVerifier implements MembershipVerifier
{
    /** @var array<string, list<string>> */
    private array $memberships = [];

    public function allow(Authenticatable $actor, OrganizationId $organization): self
    {
        $this->memberships[(string) $actor->getAuthIdentifier()][] = $organization->value;

        return $this;
    }

    public function hasActiveMembership(Authenticatable $actor, OrganizationId $organization): bool
    {
        return in_array($organization->value, $this->memberships[(string) $actor->getAuthIdentifier()] ?? [], true);
    }
}
