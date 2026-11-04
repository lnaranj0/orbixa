<?php

namespace Modules\IdentityTenancy\Infrastructure\Membership;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\DatabaseManager;
use Modules\Platform\Contracts\Tenancy\MembershipVerifier;
use Modules\Platform\Contracts\Tenancy\OrganizationId;

/**
 * Real MembershipVerifier (option A): asks the narrow SECURITY DEFINER
 * function, on the same connection TenantContext uses, so the check runs
 * inside the transaction that then sets the tenant context.
 */
final class PostgresMembershipVerifier implements MembershipVerifier
{
    public function __construct(private readonly DatabaseManager $db) {}

    public function hasActiveMembership(Authenticatable $actor, OrganizationId $organization): bool
    {
        $userId = ActorIdentifier::of($actor);

        if ($userId === null) {
            return false;
        }

        return (bool) $this->db->connection()->selectOne(
            'select identity.has_active_membership(?::uuid, ?::uuid) as member',
            [$userId, $organization->value]
        )->member;
    }
}
