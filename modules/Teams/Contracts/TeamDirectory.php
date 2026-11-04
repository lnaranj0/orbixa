<?php

namespace Modules\Teams\Contracts;

/**
 * Read contract for other modules (Portfolio visibility, 04 monitoring).
 * Always scoped to the ACTIVE organization by RLS.
 */
interface TeamDirectory
{
    /**
     * Active teams the membership currently belongs to.
     *
     * @return list<string>
     */
    public function activeTeamIdsOf(string $membershipId): array;

    public function isActive(string $teamId): bool;

    /**
     * Descendants of the team, excluding the team itself (depth <= 5).
     * The single definition of "include_descendants" for 02 and 04.
     *
     * @return list<string>
     */
    public function descendantsOf(string $teamId): array;
}
