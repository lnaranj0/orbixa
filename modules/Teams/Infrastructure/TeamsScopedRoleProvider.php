<?php

namespace Modules\Teams\Infrastructure;

use Illuminate\Database\DatabaseManager;
use Modules\Access\Contracts\Scope;
use Modules\Access\Contracts\ScopedRoleProvider;
use Modules\Access\Contracts\ScopedRoles;

/**
 * "Equipo propio" (D14): a lead of team T holds team.lead over T and its
 * descendants, i.e. over any active team whose ancestor chain (itself
 * included) contains a team the membership actively leads. Never over
 * parents or siblings.
 */
final class TeamsScopedRoleProvider implements ScopedRoleProvider
{
    public function __construct(private readonly DatabaseManager $db) {}

    public function scopedRolesOf(string $membershipId, Scope $scope): array
    {
        if ($scope->type !== Scope::TEAM) {
            return [];
        }

        $leads = $this->db->connection()->selectOne(
            "with recursive chain(id, parent_team_id, level) as (
                 select t.id, t.parent_team_id, 1 from teams.teams t where t.id = ?::uuid and t.status = 'active'
                 union all
                 select t.id, t.parent_team_id, c.level + 1
                   from teams.teams t join chain c on t.id = c.parent_team_id
                  where c.level < 5 and t.status = 'active'
             )
             select 1 as found
               from chain c
               join teams.team_members tm on tm.team_id = c.id
              where tm.membership_id = ?::uuid and tm.role = 'lead' and tm.removed_at is null
              limit 1",
            [$scope->id, $membershipId]
        );

        return $leads === null ? [] : [ScopedRoles::TEAM_LEAD];
    }
}
