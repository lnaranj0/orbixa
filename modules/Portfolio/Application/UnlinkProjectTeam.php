<?php

namespace Modules\Portfolio\Application;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Platform\Contracts\Events\ActorReference;
use Modules\Platform\Contracts\Events\DomainEvent;
use Modules\Platform\Contracts\Events\DomainEventRecorder;
use Modules\Portfolio\Contracts\ProjectLinkNotFound;
use Modules\Portfolio\Infrastructure\ProjectStore;

/** Unlinking only narrows access, so managing the project is enough. */
final class UnlinkProjectTeam
{
    public function __construct(
        private readonly ProjectGuard $guard,
        private readonly ProjectStore $projects,
        private readonly DomainEventRecorder $events,
    ) {}

    public function handle(Authenticatable $actor, string $projectId, string $teamId): void
    {
        $actorMembershipId = $this->guard->context($actor);
        $this->guard->requireManage($actor, $projectId);

        if (! ProjectStore::isUuid($teamId) || $this->projects->unlinkTeam($projectId, $teamId, $actorMembershipId) === 0) {
            throw ProjectLinkNotFound::make();
        }

        $this->events->record(
            new DomainEvent('project.team.unlinked', 1, 'project', $projectId, ['team_id' => $teamId]),
            ActorReference::human((string) $actor->getAuthIdentifier()),
        );
    }
}
