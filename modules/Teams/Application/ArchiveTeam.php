<?php

namespace Modules\Teams\Application;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Platform\Contracts\Events\ActorReference;
use Modules\Platform\Contracts\Events\DomainEvent;
use Modules\Platform\Contracts\Events\DomainEventRecorder;
use Modules\Teams\Infrastructure\TeamStore;

final class ArchiveTeam
{
    public function __construct(
        private readonly TeamGuard $guard,
        private readonly TeamStore $teams,
        private readonly DomainEventRecorder $events,
    ) {}

    public function handle(Authenticatable $actor, string $teamId): void
    {
        [$organization, $actorMembershipId] = $this->guard->context($actor);
        $team = $this->guard->requireManage($actor, $actorMembershipId, $teamId);

        if ($team['status'] === 'archived') {
            return;
        }

        $this->teams->lockHierarchy($organization);
        $this->teams->archive($teamId);

        $this->events->record(
            new DomainEvent('team.archived', 1, 'team', $teamId),
            ActorReference::human((string) $actor->getAuthIdentifier()),
        );
    }
}
