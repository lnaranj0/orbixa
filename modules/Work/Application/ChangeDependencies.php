<?php

namespace Modules\Work\Application;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Access\Contracts\Permission;
use Modules\Platform\Contracts\Events\ActorReference;
use Modules\Platform\Contracts\Events\DomainEvent;
use Modules\Platform\Contracts\Events\DomainEventRecorder;
use Modules\Work\Contracts\WorkRelationNotFound;
use Modules\Work\Contracts\WorkRuleViolation;
use Modules\Work\Infrastructure\ItemStore;

/**
 * "$blocker blocks $item" (W8). Both items must be in the same project, so
 * no item of a hidden project is ever revealed; cycles, self-dependencies
 * and more than 50 active dependencies are rejected by the database too.
 */
final class ChangeDependencies
{
    public function __construct(
        private readonly WorkGuard $guard,
        private readonly ItemStore $items,
        private readonly DomainEventRecorder $events,
    ) {}

    public function add(Authenticatable $actor, string $itemId, string $blockerId): void
    {
        $actorMembershipId = $this->guard->context($actor);
        [$item, $project] = $this->guard->itemForChange($actor, $itemId);
        $this->guard->requireChange($actor, $actorMembershipId, $item, $project, Permission::WorkEdit);

        $blocker = ItemStore::isUuid($blockerId) ? $this->items->find($blockerId) : null;
        if ($blocker === null || $blocker['project_id'] !== $project->id) {
            throw WorkRuleViolation::because(WorkRuleViolation::DEPENDENCY);
        }
        if ($blockerId === $itemId) {
            throw WorkRuleViolation::because(WorkRuleViolation::DEPENDENCY_CYCLE);
        }

        if ($this->items->addDependency($project->id, $blockerId, $itemId, $actorMembershipId)) {
            $this->items->recordActivity($itemId, 'dependencies_changed', $actorMembershipId, ['blocked_by' => ['added' => $blockerId]]);
            $this->events->record(
                new DomainEvent('work.item.blocked', 1, 'work_item', $itemId, ['project_id' => $project->id, 'blocker_item_id' => $blockerId]),
                ActorReference::human((string) $actor->getAuthIdentifier()),
            );
        }
    }

    public function remove(Authenticatable $actor, string $itemId, string $blockerId): void
    {
        $actorMembershipId = $this->guard->context($actor);
        [$item, $project] = $this->guard->itemForChange($actor, $itemId);
        $this->guard->requireChange($actor, $actorMembershipId, $item, $project, Permission::WorkEdit);

        if (! ItemStore::isUuid($blockerId) || $this->items->removeDependency($blockerId, $itemId, $actorMembershipId) === 0) {
            throw WorkRelationNotFound::make();
        }
        $this->items->recordActivity($itemId, 'dependencies_changed', $actorMembershipId, ['blocked_by' => ['removed' => $blockerId]]);
        $this->events->record(
            new DomainEvent('work.item.updated', 1, 'work_item', $itemId, ['project_id' => $project->id, 'fields' => ['dependencies']]),
            ActorReference::human((string) $actor->getAuthIdentifier()),
        );
    }
}
