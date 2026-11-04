<?php

namespace Modules\Teams\Application;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Teams\Infrastructure\TeamStore;

/**
 * Which teams the actor may list or read (D15): the full inventory with the
 * organization-level teams.manage; otherwise their own active teams plus
 * the branches they lead.
 */
final class TeamVisibility
{
    public function __construct(
        private readonly TeamGuard $guard,
        private readonly TeamStore $teams,
    ) {}

    /**
     * @return list<string>|null null = every team of the active organization
     */
    public function visibleIds(Authenticatable $actor): ?array
    {
        [, $membershipId] = $this->guard->context($actor);

        if ($this->guard->managesOrganizationTeams($actor)) {
            return null;
        }

        $ids = $this->teams->activeTeamIdsOf($membershipId);
        foreach ($this->teams->ledTeamIdsOf($membershipId) as $led) {
            array_push($ids, ...$this->teams->descendantsOf($led));
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return array{id: string, name: string, parent_team_id: string|null, status: string, version: int}|null
     */
    public function find(Authenticatable $actor, string $teamId): ?array
    {
        if (! TeamStore::isUuid($teamId)) {
            return null;
        }
        $visible = $this->visibleIds($actor);
        if ($visible !== null && ! in_array($teamId, $visible, true)) {
            return null;
        }

        return $this->teams->page([$teamId], [], null, 1)[0] ?? null;
    }
}
