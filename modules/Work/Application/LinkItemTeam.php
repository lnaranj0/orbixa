<?php

namespace Modules\Work\Application;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Access\Contracts\Permission;
use Modules\Platform\Contracts\Events\ActorReference;
use Modules\Platform\Contracts\Events\DomainEvent;
use Modules\Platform\Contracts\Events\DomainEventRecorder;
use Modules\Teams\Contracts\TeamDirectory;
use Modules\Work\Contracts\WorkRelationNotFound;
use Modules\Work\Contracts\WorkRuleViolation;
use Modules\Work\Infrastructure\ItemStore;

/**
 * Associates teams with a work item (W7). Needs work.assign over the item;
 * the team must be active (missing, foreign or archived: one 422). The
 * association grants no visibility.
 */
final class LinkItemTeam
{
    private const MAX_TEAMS = 20;

    public function __construct(
        private readonly WorkGuard $guard,
        private readonly ItemStore $items,
        private readonly TeamDirectory $teams,
        private readonly DomainEventRecorder $events,
    ) {}

    public function link(Authenticatable $actor, string $itemId, string $teamId): void
    {
        $actorMembershipId = $this->guard->context($actor);
        [$item, $project] = $this->guard->itemForChange($actor, $itemId);
        $this->guard->requireChange($actor, $actorMembershipId, $item, $project, Permission::WorkAssign);

        if (! ItemStore::isUuid($teamId) || ! $this->teams->isActive($teamId)) {
            throw WorkRuleViolation::because(WorkRuleViolation::TEAM);
        }
        $this->items->lockItem($itemId);
        if ($this->items->activeTeamLinkCount($itemId) >= self::MAX_TEAMS) {
            throw WorkRuleViolation::because(WorkRuleViolation::TEAM_LIMIT);
        }
        if ($this->items->linkTeam($itemId, $teamId, $actorMembershipId)) {
            $this->changed($actor, $actorMembershipId, $itemId, $project->id, ['linked' => $teamId]);
        }
    }

    public function unlink(Authenticatable $actor, string $itemId, string $teamId): void
    {
        $actorMembershipId = $this->guard->context($actor);
        [$item, $project] = $this->guard->itemForChange($actor, $itemId);
        $this->guard->requireChange($actor, $actorMembershipId, $item, $project, Permission::WorkAssign);

        if (! ItemStore::isUuid($teamId) || $this->items->unlinkTeam($itemId, $teamId, $actorMembershipId) === 0) {
            throw WorkRelationNotFound::make();
        }
        $this->changed($actor, $actorMembershipId, $itemId, $project->id, ['unlinked' => $teamId]);
    }

    /**
     * @param  array<string, string>  $change
     */
    private function changed(Authenticatable $actor, string $actorMembershipId, string $itemId, string $projectId, array $change): void
    {
        $this->items->recordActivity($itemId, 'teams_changed', $actorMembershipId, ['teams' => $change]);
        $this->events->record(
            new DomainEvent('work.item.updated', 1, 'work_item', $itemId, ['project_id' => $projectId, 'fields' => ['teams']]),
            ActorReference::human((string) $actor->getAuthIdentifier()),
        );
    }
}
