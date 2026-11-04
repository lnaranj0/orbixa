<?php

namespace Modules\Teams\Application;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Platform\Contracts\Events\ActorReference;
use Modules\Platform\Contracts\Events\DomainEvent;
use Modules\Platform\Contracts\Events\DomainEventRecorder;
use Modules\Teams\Contracts\HierarchyViolation;
use Modules\Teams\Contracts\TeamOperationDenied;
use Modules\Teams\Infrastructure\TeamStore;

/** Reparents a team (or makes it a root with null); history + team.moved. */
final class MoveTeam
{
    public function __construct(
        private readonly TeamGuard $guard,
        private readonly TeamStore $teams,
        private readonly DomainEventRecorder $events,
    ) {}

    public function handle(Authenticatable $actor, string $teamId, ?string $newParentTeamId): void
    {
        [$organization, $actorMembershipId] = $this->guard->context($actor);
        $this->guard->requireManage($actor, $actorMembershipId, $teamId);

        if ($newParentTeamId !== null && ! TeamStore::isUuid($newParentTeamId)) {
            throw HierarchyViolation::because(HierarchyViolation::PARENT);
        }
        // Both ends: a lead cannot move their team out of their branch, and
        // only the organization level can turn a team into a root.
        if ($newParentTeamId === null) {
            if (! $this->guard->managesOrganizationTeams($actor)) {
                throw TeamOperationDenied::because('teams.manage is required to create root teams.');
            }
        } else {
            $this->guard->requireManageDestination($actor, $actorMembershipId, $newParentTeamId);
        }

        $this->teams->lockHierarchy($organization);
        $team = $this->teams->find($teamId, forUpdate: true) ?? throw HierarchyViolation::because(HierarchyViolation::PARENT);

        if ($team['status'] !== 'active') {
            throw HierarchyViolation::because(HierarchyViolation::ARCHIVED);
        }
        if ($team['parent_team_id'] === $newParentTeamId) {
            return;
        }

        $this->teams->move($teamId, $team['parent_team_id'], $newParentTeamId, $actorMembershipId);

        $this->events->record(
            new DomainEvent('team.moved', 1, 'team', $teamId, [
                'old_parent_team_id' => $team['parent_team_id'],
                'new_parent_team_id' => $newParentTeamId,
            ]),
            ActorReference::human((string) $actor->getAuthIdentifier()),
        );
    }
}
