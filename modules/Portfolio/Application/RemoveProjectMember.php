<?php

namespace Modules\Portfolio\Application;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Platform\Contracts\Events\ActorReference;
use Modules\Platform\Contracts\Events\DomainEvent;
use Modules\Platform\Contracts\Events\DomainEventRecorder;
use Modules\Portfolio\Contracts\ProjectMemberNotFound;
use Modules\Portfolio\Contracts\ProjectOperationDenied;
use Modules\Portfolio\Infrastructure\ProjectStore;

/** Closes an active project membership; removing a manager is organization-level only (D6). */
final class RemoveProjectMember
{
    public function __construct(
        private readonly ProjectGuard $guard,
        private readonly ProjectStore $projects,
        private readonly DomainEventRecorder $events,
    ) {}

    public function handle(Authenticatable $actor, string $projectId, string $membershipId): void
    {
        $actorMembershipId = $this->guard->context($actor);
        $this->guard->requireManage($actor, $projectId);

        if ($membershipId === $actorMembershipId) {
            throw ProjectOperationDenied::because('Use the leave flow to remove yourself.');
        }

        $role = ProjectStore::isUuid($membershipId) ? $this->projects->lockMemberRole($projectId, $membershipId) : null;
        if ($role === null) {
            throw ProjectMemberNotFound::make();
        }
        if ($role === 'manager' && ! $this->guard->managesOrganizationProjects($actor)) {
            throw ProjectOperationDenied::because('Removing a manager requires the organization-level projects.manage.');
        }

        $this->projects->removeMember($projectId, $membershipId, $actorMembershipId);
        $this->events->record(
            new DomainEvent('project.member.removed', 1, 'project', $projectId, ['membership_id' => $membershipId]),
            ActorReference::human((string) $actor->getAuthIdentifier()),
        );
    }
}
