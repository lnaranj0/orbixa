<?php

namespace Modules\Teams\Application;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Platform\Contracts\Events\ActorReference;
use Modules\Platform\Contracts\Events\DomainEvent;
use Modules\Platform\Contracts\Events\DomainEventRecorder;
use Modules\Teams\Contracts\TeamMemberNotFound;
use Modules\Teams\Contracts\TeamOperationDenied;
use Modules\Teams\Infrastructure\TeamStore;

final class RemoveTeamMember
{
    public function __construct(
        private readonly TeamGuard $guard,
        private readonly TeamStore $teams,
        private readonly DomainEventRecorder $events,
    ) {}

    public function handle(Authenticatable $actor, string $teamId, string $membershipId): void
    {
        [, $actorMembershipId] = $this->guard->context($actor);
        $this->guard->requireManage($actor, $actorMembershipId, $teamId);

        if ($membershipId === $actorMembershipId) {
            throw TeamOperationDenied::because('Use the leave flow to remove yourself.');
        }
        $role = TeamStore::isUuid($membershipId) ? $this->teams->lockMemberRole($teamId, $membershipId) : null;
        if ($role === null) {
            throw TeamMemberNotFound::make();
        }
        // Removing a lead is a demotion: organization-level only, like appointing one (D14).
        if ($role === 'lead' && ! $this->guard->managesOrganizationTeams($actor)) {
            throw TeamOperationDenied::because('Removing a lead requires the organization-level teams.manage.');
        }

        $this->teams->removeMember($teamId, $membershipId, $actorMembershipId, 'removed');

        $this->events->record(
            new DomainEvent('team.member.removed', 1, 'team', $teamId, ['membership_id' => $membershipId]),
            ActorReference::human((string) $actor->getAuthIdentifier()),
        );
    }
}
