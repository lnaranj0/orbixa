<?php

namespace Modules\Teams\Infrastructure;

use Modules\Teams\Contracts\TeamDirectory;

final class PostgresTeamDirectory implements TeamDirectory
{
    public function __construct(private readonly TeamStore $teams) {}

    public function activeTeamIdsOf(string $membershipId): array
    {
        return TeamStore::isUuid($membershipId) ? $this->teams->activeTeamIdsOf($membershipId) : [];
    }

    public function isActive(string $teamId): bool
    {
        return ($this->teams->find($teamId)['status'] ?? null) === 'active';
    }

    public function descendantsOf(string $teamId): array
    {
        return TeamStore::isUuid($teamId) ? $this->teams->descendantsOf($teamId) : [];
    }

    public function activeDescendantsOf(string $teamId): array
    {
        return TeamStore::isUuid($teamId) ? $this->teams->activeDescendantsOf($teamId) : [];
    }
}
