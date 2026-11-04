<?php

namespace Modules\IdentityTenancy\Infrastructure\Membership;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\DatabaseManager;
use Modules\IdentityTenancy\Contracts\MembershipDirectory;
use Modules\IdentityTenancy\Contracts\OrganizationSummary;
use Modules\Platform\Contracts\Tenancy\OrganizationId;

final class PostgresMembershipDirectory implements MembershipDirectory
{
    public function __construct(private readonly DatabaseManager $db) {}

    public function organizationsFor(Authenticatable $actor): array
    {
        $userId = ActorIdentifier::of($actor);

        if ($userId === null) {
            return [];
        }

        $rows = $this->db->connection()->select(
            'select id::text as id, name from identity.organizations_for_user(?::uuid)',
            [$userId]
        );

        $summaries = [];
        foreach ($rows as $row) {
            $columns = (array) $row;
            $summaries[] = new OrganizationSummary(
                OrganizationId::fromString((string) $columns['id']),
                (string) $columns['name'],
            );
        }

        return $summaries;
    }
}
