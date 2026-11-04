<?php

namespace Modules\IdentityTenancy\Infrastructure\Membership;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\DatabaseManager;
use Modules\IdentityTenancy\Contracts\CurrentMembership;
use Modules\Platform\Contracts\Tenancy\TenantContext;

final class PostgresCurrentMembership implements CurrentMembership
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly DatabaseManager $db,
    ) {}

    public function idFor(Authenticatable $actor): ?string
    {
        $userId = ActorIdentifier::of($actor);

        if ($userId === null || $this->tenant->current() === null) {
            return null;
        }

        // RLS limits this to the active organization.
        $row = $this->db->connection()->selectOne(
            "select id::text as id from identity.organization_memberships where user_id = ?::uuid and status = 'active'",
            [$userId]
        );

        return $row === null ? null : (string) ((array) $row)['id'];
    }
}
