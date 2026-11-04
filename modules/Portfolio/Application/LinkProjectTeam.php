<?php

namespace Modules\Portfolio\Application;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Platform\Contracts\Events\ActorReference;
use Modules\Platform\Contracts\Events\DomainEvent;
use Modules\Platform\Contracts\Events\DomainEventRecorder;
use Modules\Portfolio\Infrastructure\ProjectStore;

/**
 * Links a team directly to a project (D2, D6): the actor must manage both
 * the project and the team, so nobody gains access to a project by linking
 * a team they lead. Restricted projects are therefore linked only by
 * owner/admin or the project's manager. Idempotent.
 */
final class LinkProjectTeam
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
        $this->guard->requireLinkableTeam($actor, $actorMembershipId, $teamId);

        if ($this->projects->linkTeam($projectId, $teamId, $actorMembershipId)) {
            $this->events->record(
                new DomainEvent('project.team.linked', 1, 'project', $projectId, ['team_id' => $teamId]),
                ActorReference::human((string) $actor->getAuthIdentifier()),
            );
        }
    }
}
