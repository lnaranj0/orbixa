<?php

namespace Modules\Work\Application;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Access\Contracts\Permission;
use Modules\Platform\Contracts\Events\ActorReference;
use Modules\Platform\Contracts\Events\DomainEvent;
use Modules\Platform\Contracts\Events\DomainEventRecorder;
use Modules\Platform\Contracts\Validation\InputRejected;
use Modules\Work\Contracts\Workflow\TransitionPolicy;
use Modules\Work\Contracts\Workflow\WorkflowStatus;
use Modules\Work\Contracts\WorkOperationDenied;
use Modules\Work\Contracts\WorkRuleViolation;
use Modules\Work\Contracts\WorkVersionConflict;
use Modules\Work\Domain\ItemRules;
use Modules\Work\Infrastructure\ItemStore;
use Modules\Work\Infrastructure\RejectedTransitionAudit;

/**
 * Status change along the project's configured workflow (05 P2-P6, P10).
 * WorkGuard decides who may transition at all; the workflow can only
 * restrict it: a transition must be configured, may need a reason, may
 * check blockers, respects the destination's WIP limit, and anything into
 * the category done needs work.approve (D-A).
 */
final class TransitionWorkItem
{
    public function __construct(
        private readonly WorkGuard $guard,
        private readonly ItemStore $items,
        private readonly DomainEventRecorder $events,
        private readonly TransitionPolicy $workflow,
        private readonly RejectedTransitionAudit $rejections,
    ) {}

    /**
     * @param  string  $to  a status id of the project's workflow, or a category (its first status)
     */
    public function handle(Authenticatable $actor, string $itemId, int $expectedVersion, string $to, ?string $reason = null): int
    {
        $actorMembershipId = $this->guard->context($actor);
        [$item, $project] = $this->guard->itemForChange($actor, $itemId);
        $this->guard->requireChange($actor, $actorMembershipId, $item, $project, Permission::WorkTransition);

        $this->workflow->ensureWorkflow($project->id);
        $from = ($item['workflow_status_id'] === null ? null : $this->workflow->status($project->id, $item['workflow_status_id']))
            ?? $this->workflow->statusForCategory($project->id, $item['status'])
            ?? throw WorkRuleViolation::because(WorkRuleViolation::TRANSITION);
        $target = $this->target($project->id, $to) ?? throw WorkRuleViolation::because(WorkRuleViolation::TRANSITION);

        $rule = $this->workflow->transition($project->id, $from->id, $target->id);
        if ($rule === null) {
            $this->rejections->record($actor, $itemId, $project->id, $from->id, $target->id);

            throw WorkRuleViolation::because(WorkRuleViolation::TRANSITION);
        }
        if (($rule->requiredPermission === Permission::WorkApprove->value || $target->category === 'done')
            && ! $this->guard->allowsInProject($actor, Permission::WorkApprove, $project->id)) {
            throw WorkOperationDenied::because('work.approve is required for this transition.');
        }

        $reason = $reason === null ? null : trim($reason);
        if ($rule->requiresReason && ($reason === null || $reason === '')) {
            throw WorkRuleViolation::because(WorkRuleViolation::REASON);
        }
        if ($reason !== null && mb_strlen($reason) > 500) {
            throw InputRejected::because('Reason must be at most 500 characters.');
        }
        if ($rule->checkBlockers && $this->items->openBlockerCount($itemId) > 0) {
            throw WorkRuleViolation::because(WorkRuleViolation::BLOCKED);
        }
        if ($target->wipLimit !== null) {
            $this->items->lockStatus($target->id);
            if ($this->items->countInStatus($target->id) >= $target->wipLimit) {
                throw WorkRuleViolation::because(WorkRuleViolation::WIP); // no counts: they could include hidden items
            }
        }

        $completedAt = $target->category === 'done' ? now()->toIso8601String() : null;
        if ($item['version'] !== $expectedVersion || ! $this->items->update($itemId, $expectedVersion, [
            'status' => $target->category, 'workflow_status_id' => $target->id, 'completed_at' => $completedAt,
        ])) {
            throw WorkVersionConflict::make();
        }
        $this->items->recordStatusChange($itemId, $project->id, $from->id, $from->category, $target->id, $target->category, $actorMembershipId);

        // The reason is free text: it stays in the activity, never in events.
        $this->items->recordActivity($itemId, 'transitioned', $actorMembershipId, array_filter([
            'status' => ['from' => $from->category, 'to' => $target->category],
            'workflow_status_id' => ['from' => $from->id, 'to' => $target->id],
            'reason' => $reason === null || $reason === '' ? null : ['text' => $reason],
        ]));

        $by = ActorReference::human((string) $actor->getAuthIdentifier());
        $this->events->record(new DomainEvent('work.item.transitioned', 2, 'work_item', $itemId, [
            'project_id' => $project->id, 'from' => $from->category, 'to' => $target->category,
            'from_status_id' => $from->id, 'to_status_id' => $target->id,
        ]), $by);
        if ($target->category === 'done') {
            $this->events->record(new DomainEvent('work.item.completed', 1, 'work_item', $itemId, ['project_id' => $project->id]), $by);
        } elseif ($from->category === 'done' || $from->category === 'canceled') {
            $this->events->record(new DomainEvent('work.item.reopened', 1, 'work_item', $itemId, ['project_id' => $project->id, 'from' => $from->category]), $by);
        }

        return $expectedVersion + 1;
    }

    private function target(string $projectId, string $to): ?WorkflowStatus
    {
        if (ItemStore::isUuid($to)) {
            return $this->workflow->status($projectId, $to);
        }

        return in_array($to, ItemRules::STATUSES, true) ? $this->workflow->statusForCategory($projectId, $to) : null;
    }
}
