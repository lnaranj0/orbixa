<?php

namespace Modules\Work\Application;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Access\Contracts\Permission;
use Modules\Platform\Contracts\Events\ActorReference;
use Modules\Platform\Contracts\Events\DomainEvent;
use Modules\Platform\Contracts\Events\DomainEventRecorder;
use Modules\Platform\Contracts\Validation\InputRejected;
use Modules\Work\Contracts\WorkRuleViolation;
use Modules\Work\Contracts\WorkVersionConflict;
use Modules\Work\Domain\ItemRules;
use Modules\Work\Infrastructure\ItemStore;

/** Status change along the fixed base flow of 03 (W3); 05 replaces the graph. */
final class TransitionWorkItem
{
    public function __construct(
        private readonly WorkGuard $guard,
        private readonly ItemStore $items,
        private readonly DomainEventRecorder $events,
    ) {}

    public function handle(Authenticatable $actor, string $itemId, int $expectedVersion, string $to, ?string $reason = null): int
    {
        $actorMembershipId = $this->guard->context($actor);
        [$item, $project] = $this->guard->item($actor, $itemId);
        $this->guard->requireChange($actor, $actorMembershipId, $item, $project, Permission::WorkTransition);

        $from = $item['status'];
        if (! ItemRules::allows($from, $to)) {
            throw WorkRuleViolation::because(WorkRuleViolation::TRANSITION);
        }
        $reason = $reason === null ? null : trim($reason);
        if (ItemRules::needsReason($from, $to) && ($reason === null || $reason === '')) {
            throw WorkRuleViolation::because(WorkRuleViolation::REASON);
        }
        if ($reason !== null && mb_strlen($reason) > 500) {
            throw InputRejected::because('Reason must be at most 500 characters.');
        }

        $completedAt = $to === 'done' ? now()->toIso8601String() : null;
        if ($item['version'] !== $expectedVersion || ! $this->items->update($itemId, $expectedVersion, ['status' => $to, 'completed_at' => $completedAt])) {
            throw WorkVersionConflict::make();
        }

        // The reason is free text: it stays in the activity, never in events.
        $this->items->recordActivity($itemId, 'transitioned', $actorMembershipId, array_filter([
            'status' => ['from' => $from, 'to' => $to],
            'reason' => $reason === null || $reason === '' ? null : ['text' => $reason],
        ]));

        $by = ActorReference::human((string) $actor->getAuthIdentifier());
        $this->events->record(new DomainEvent('work.item.transitioned', 1, 'work_item', $itemId, ['project_id' => $project->id, 'from' => $from, 'to' => $to]), $by);
        if ($to === 'done') {
            $this->events->record(new DomainEvent('work.item.completed', 1, 'work_item', $itemId, ['project_id' => $project->id]), $by);
        } elseif ($from === 'done' || $from === 'canceled') {
            $this->events->record(new DomainEvent('work.item.reopened', 1, 'work_item', $itemId, ['project_id' => $project->id, 'from' => $from]), $by);
        }

        return $expectedVersion + 1;
    }
}
