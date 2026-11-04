<?php

namespace Modules\IdentityTenancy\Infrastructure\Membership;

use Illuminate\Database\DatabaseManager;
use Modules\IdentityTenancy\Contracts\MembershipLookup;

final class PostgresMembershipLookup implements MembershipLookup
{
    public function __construct(private readonly DatabaseManager $db) {}

    public function lockActive(string $membershipId): bool
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $membershipId) !== 1) {
            return false;
        }

        return $this->db->connection()->selectOne(
            "select 1 as found from identity.organization_memberships where id = ?::uuid and status = 'active' for share",
            [$membershipId]
        ) !== null;
    }
}
