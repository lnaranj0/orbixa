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
use Modules\Work\Domain\ActivityDiff;
use Modules\Work\Domain\ItemRules;
use Modules\Work\Infrastructure\ItemStore;

/**
 * Optimistic update of the editable attributes (W9, W10). Status changes go
 * through TransitionWorkItem. A stale expected version changes nothing and
 * fails with WorkVersionConflict (409).
 */
final class UpdateWorkItem
{
    private const FIELDS = ['type', 'parent_id', 'title', 'description', 'priority', 'estimate', 'due_on'];

    public function __construct(
        private readonly WorkGuard $guard,
        private readonly ItemStore $items,
        private readonly DomainEventRecorder $events,
    ) {}

    /**
     * @param  array<string, mixed>  $changes
     */
    public function handle(Authenticatable $actor, string $itemId, int $expectedVersion, array $changes): int
    {
        $actorMembershipId = $this->guard->context($actor);
        [$item, $project] = $this->guard->itemForChange($actor, $itemId);
        $this->guard->requireChange($actor, $actorMembershipId, $item, $project, Permission::WorkEdit);

        $columns = $this->validated($changes);
        if (array_key_exists('parent_id', $columns) && $columns['parent_id'] !== null) {
            $parent = $this->items->find($columns['parent_id']);
            if ($parent === null || $parent['project_id'] !== $project->id) {
                throw WorkRuleViolation::because(WorkRuleViolation::PARENT);
            }
            if (! ItemRules::canParent($parent['type'], $columns['type'] ?? $item['type'])) {
                throw WorkRuleViolation::because(WorkRuleViolation::TYPE);
            }
        }
        if (array_key_exists('parent_id', $columns) || array_key_exists('type', $columns)) {
            $this->items->lockHierarchy($project->id);
        }

        if ($item['version'] !== $expectedVersion || ! $this->items->update($itemId, $expectedVersion, $columns)) {
            throw WorkVersionConflict::make();
        }

        $diff = ActivityDiff::of($item, $columns);
        if ($diff !== []) {
            $this->items->recordActivity($itemId, 'updated', $actorMembershipId, $diff);
        }
        $this->events->record(
            new DomainEvent('work.item.updated', 1, 'work_item', $itemId, ['project_id' => $project->id, 'fields' => array_keys($diff)]),
            ActorReference::human((string) $actor->getAuthIdentifier()),
        );

        return $expectedVersion + 1;
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, string|null>
     */
    private function validated(array $changes): array
    {
        if ($changes === [] || array_diff(array_keys($changes), self::FIELDS) !== []) {
            throw InputRejected::because('Only type, parent, title, description, priority, estimate and due date can be changed.');
        }

        $columns = [];
        foreach ($changes as $field => $value) {
            if ($value !== null && ! is_string($value) && ! (in_array($field, ['estimate'], true) && (is_int($value) || is_float($value)))) {
                throw InputRejected::because("Invalid {$field}.");
            }
            $columns[$field] = match ($field) {
                'type' => ItemInput::type((string) $value),
                'title' => ItemInput::title((string) $value),
                'description' => ItemInput::description($value === null ? null : (string) $value),
                'priority' => ItemInput::priority((string) $value),
                'estimate' => ItemInput::estimate($value),
                'due_on' => ItemInput::dueOn($value === null ? null : (string) $value),
                default => $value === null ? null : (string) $value, // parent_id, checked by the caller
            };
        }

        return $columns;
    }
}
