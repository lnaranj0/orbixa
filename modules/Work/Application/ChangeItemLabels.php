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
 * Adds or removes a label (W4). The label must belong to the item's project
 * (enforced by the foreign key); any other label is one generic 422.
 */
final class ChangeItemLabels
{
    private const MAX_LABELS = 20;

    public function __construct(
        private readonly WorkGuard $guard,
        private readonly ItemStore $items,
        private readonly DomainEventRecorder $events,
    ) {}

    public function add(Authenticatable $actor, string $itemId, string $labelId): void
    {
        $actorMembershipId = $this->guard->context($actor);
        [$item, $project] = $this->guard->itemForChange($actor, $itemId);
        $this->guard->requireChange($actor, $actorMembershipId, $item, $project, Permission::WorkEdit);

        if (! ItemStore::isUuid($labelId)) {
            throw WorkRuleViolation::because(WorkRuleViolation::LABEL);
        }
        $this->items->lockItem($itemId);
        if ($this->items->activeLabelCount($itemId) >= self::MAX_LABELS) {
            throw WorkRuleViolation::because(WorkRuleViolation::LABEL_LIMIT);
        }
        if ($this->items->addLabel($project->id, $itemId, $labelId, $actorMembershipId)) {
            $this->changed($actor, $actorMembershipId, $itemId, $project->id, ['added' => $labelId]);
        }
    }

    public function remove(Authenticatable $actor, string $itemId, string $labelId): void
    {
        $actorMembershipId = $this->guard->context($actor);
        [$item, $project] = $this->guard->itemForChange($actor, $itemId);
        $this->guard->requireChange($actor, $actorMembershipId, $item, $project, Permission::WorkEdit);

        if (! ItemStore::isUuid($labelId) || $this->items->removeLabel($itemId, $labelId, $actorMembershipId) === 0) {
            throw WorkRelationNotFound::make();
        }
        $this->changed($actor, $actorMembershipId, $itemId, $project->id, ['removed' => $labelId]);
    }

    /**
     * @param  array<string, string>  $change
     */
    private function changed(Authenticatable $actor, string $actorMembershipId, string $itemId, string $projectId, array $change): void
    {
        $this->items->recordActivity($itemId, 'labels_changed', $actorMembershipId, ['labels' => $change]);
        $this->events->record(
            new DomainEvent('work.item.updated', 1, 'work_item', $itemId, ['project_id' => $projectId, 'fields' => ['labels']]),
            ActorReference::human((string) $actor->getAuthIdentifier()),
        );
    }
}
