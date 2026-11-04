<?php

namespace Modules\Teams\Application;

use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;
use Modules\Platform\Contracts\Events\ActorReference;
use Modules\Platform\Contracts\Events\DomainEvent;
use Modules\Platform\Contracts\Events\DomainEventRecorder;
use Modules\Teams\Contracts\HierarchyViolation;
use Modules\Teams\Infrastructure\TeamStore;

final class CreateTeam
{
    public function __construct(
        private readonly TeamGuard $guard,
        private readonly TeamStore $teams,
        private readonly DomainEventRecorder $events,
    ) {}

    public function handle(Authenticatable $actor, string $name, ?string $parentTeamId = null): string
    {
        [$organization, $actorMembershipId] = $this->guard->context($actor);

        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) {
            throw new InvalidArgumentException('Team name must be 1-120 characters.');
        }
        if ($parentTeamId !== null && ! TeamStore::isUuid($parentTeamId)) {
            throw HierarchyViolation::because(HierarchyViolation::PARENT);
        }
        $this->guard->requireCreate($actor, $actorMembershipId, $parentTeamId);

        $this->teams->lockHierarchy($organization);
        $teamId = $this->teams->create($name, $parentTeamId, $actorMembershipId);

        $this->events->record(
            new DomainEvent('team.created', 1, 'team', $teamId, ['parent_team_id' => $parentTeamId]),
            ActorReference::human((string) $actor->getAuthIdentifier()),
        );

        return $teamId;
    }
}
