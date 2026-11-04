<?php

namespace Modules\IdentityTenancy\Contracts;

/**
 * Locks an ACTIVE membership of the active organization (FOR SHARE) for the
 * rest of the transaction, so another module can attach it to a team or
 * project without racing a concurrent revocation (which takes FOR UPDATE).
 */
interface MembershipLookup
{
    /** False when the membership is not active in the active organization. */
    public function lockActive(string $membershipId): bool;
}
