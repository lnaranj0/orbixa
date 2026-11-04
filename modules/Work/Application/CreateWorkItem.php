<?php

namespace Modules\Work\Application;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Platform\Contracts\Events\ActorReference;
use Modules\Platform\Contracts\Events\DomainEvent;
use Modules\Platform\Contracts\Events\DomainEventRecorder;
use Modules\Work\Contracts\Workflow\TransitionPolicy;
use Modules\Work\Contracts\WorkRuleViolation;
use Modules\Work\Domain\ItemRules;
use Modules\Work\Infrastructure\ItemStore;

/**
 * Creates a work item with the next sequential key of its project (W1, W2,
 * W10). The parent must be an item of the same project with a compatible
 * type; any other parent is one generic 422.
 */
final class CreateWorkItem
{
    public function __construct(
        private readonly WorkGuard $guard,
        private readonly ItemStore $items,
        private readonly DomainEventRecorder $events,
        private readonly TransitionPolicy $workflow,
    ) {}

    /**
     * @param  array{type: string, title: string, description?: string|null, parent_id?: string|null, priority?: string, estimate?: int|float|string|null, due_on?: string|null}  $input
     */
    public function handle(Authenticatable $actor, string $projectId, array $input): string
    {
        $actorMembershipId = $this->guard->context($actor);
        $project = $this->guard->project($actor, $projectId);
        $this->guard->requireCreate($actor, $project);

        $values = [
            'type' => ItemInput::type($input['type']),
            'parent_id' => $input['parent_id'] ?? null,
            'title' => ItemInput::title($input['title']),
            'description' => ItemInput::description($input['description'] ?? null),
            'priority' => ItemInput::priority($input['priority'] ?? 'none'),
            'estimate' => ItemInput::estimate($input['estimate'] ?? null),
            'due_on' => ItemInput::dueOn($input['due_on'] ?? null),
        ];
        if ($values['parent_id'] !== null) {
            $parent = $this->items->find($values['parent_id']);
            if ($parent === null || $parent['project_id'] !== $project->id) {
                throw WorkRuleViolation::because(WorkRuleViolation::PARENT);
            }
            if (! ItemRules::canParent($parent['type'], $values['type'])) {
                throw WorkRuleViolation::because(WorkRuleViolation::TYPE);
            }
            $this->items->lockHierarchy($project->id);
        }

        $this->workflow->ensureWorkflow($project->id);
        $initial = $this->workflow->initialStatus($project->id);
        $number = $this->items->allocateNumber($project->id);
        $itemId = $this->items->create($project->id, $number, "{$project->key}-{$number}", $values, $initial->id, $initial->category, $actorMembershipId);
        $this->items->recordStatusChange($itemId, $project->id, null, null, $initial->id, $initial->category, $actorMembershipId);

        $this->items->recordActivity($itemId, 'created', $actorMembershipId, [
            'type' => ['to' => $values['type']],
            'status' => ['to' => $initial->category],
            'workflow_status_id' => ['to' => $initial->id],
            'priority' => ['to' => $values['priority']],
        ]);
        $this->events->record(
            new DomainEvent('work.item.created', 1, 'work_item', $itemId, [
                'project_id' => $project->id,
                'type' => $values['type'],
                'parent_id' => $values['parent_id'],
            ]),
            ActorReference::human((string) $actor->getAuthIdentifier()),
        );

        return $itemId;
    }
}
