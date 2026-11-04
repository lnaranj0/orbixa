<?php

namespace Modules\Workflow\Application;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Access\Contracts\Permission;
use Modules\Access\Contracts\Scope;
use Modules\IdentityTenancy\Contracts\CurrentMembership;
use Modules\IdentityTenancy\Contracts\MembershipLookup;
use Modules\Platform\Contracts\Events\ActorReference;
use Modules\Platform\Contracts\Events\DomainEvent;
use Modules\Platform\Contracts\Events\DomainEventRecorder;
use Modules\Platform\Contracts\Tenancy\TenantContext;
use Modules\Platform\Contracts\Tenancy\TenantContextRequired;
use Modules\Platform\Contracts\Validation\InputRejected;
use Modules\Portfolio\Contracts\ProjectDirectory;
use Modules\Work\Contracts\Workflow\StatusUsage;
use Modules\Work\Contracts\Workflow\TransitionPolicy;
use Modules\Workflow\Contracts\WorkflowNotFound;
use Modules\Workflow\Contracts\WorkflowOperationDenied;
use Modules\Workflow\Contracts\WorkflowRuleViolation;
use Modules\Workflow\Contracts\WorkflowVersionConflict;
use Modules\Workflow\Infrastructure\WorkflowStore;

/**
 * Workflow configuration (05 P3). Needs work.edit over the project
 * (organization role or project.manager). Every change bumps the
 * configuration version optimistically (409 when stale) and records
 * workflow.updated with ids only. The configuration can only restrict
 * WorkGuard: permissions come from an allowlist and anything into done
 * requires work.approve.
 */
final class ConfigureWorkflow
{
    public const CATEGORIES = ['backlog', 'ready', 'active', 'review', 'done', 'canceled'];

    public const PERMISSIONS = ['work.transition', 'work.approve'];

    private const MAX_STATUSES = 20;

    private const MAX_TRANSITIONS = 100;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly CurrentMembership $memberships,
        private readonly MembershipLookup $lookup,
        private readonly Gate $gate,
        private readonly ProjectDirectory $projects,
        private readonly TransitionPolicy $policy,
        private readonly StatusUsage $usage,
        private readonly WorkflowStore $workflows,
        private readonly DomainEventRecorder $events,
    ) {}

    /**
     * @return array{project_id: string, workflow_id: string, version: int}
     */
    public function workflowFor(Authenticatable $actor, string $projectId, bool $forChange): array
    {
        $this->tenant->current() ?? throw TenantContextRequired::forOperation('Workflow');
        $project = WorkflowStore::isUuid($projectId) ? $this->projects->reference($actor, $projectId) : null;
        if ($project === null) {
            throw WorkflowNotFound::make();
        }
        if ($forChange) {
            $membershipId = $this->memberships->idFor($actor);
            if ($membershipId === null || ! $this->lookup->lockActive($membershipId)) {
                throw WorkflowOperationDenied::because('No active membership.');
            }
            if (! $this->gate->forUser($actor)->allows(Permission::WorkEdit->value, [Scope::project($project->id)])) {
                throw WorkflowOperationDenied::because('work.edit over the project is required.');
            }
            if ($project->isArchived()) {
                throw InputRejected::because('The project is archived.');
            }
        }
        // Only changes create the default workflow; reads never write (review B8).
        $workflow = $this->workflows->workflowOf($project->id);
        if ($workflow === null) {
            if (! $forChange) {
                return ['project_id' => $project->id, 'workflow_id' => '', 'version' => 0];
            }
            $this->policy->ensureWorkflow($project->id);
            $workflow = $this->workflows->workflowOf($project->id) ?? throw WorkflowNotFound::make();
        }

        return ['project_id' => $project->id, 'workflow_id' => $workflow['id'], 'version' => $workflow['version']];
    }

    public function addStatus(Authenticatable $actor, string $projectId, int $expectedVersion, string $name, string $category, int $position, ?int $wipLimit): string
    {
        $workflow = $this->begin($actor, $projectId, $expectedVersion);
        if (! in_array($category, self::CATEGORIES, true)) {
            throw InputRejected::because('Invalid category.');
        }
        if (count($this->workflows->statuses($workflow['workflow_id'])) >= self::MAX_STATUSES) {
            throw WorkflowRuleViolation::because(WorkflowRuleViolation::LIMIT);
        }
        $statusId = $this->workflows->addStatus($workflow['project_id'], $workflow['workflow_id'], self::name($name), $category, self::position($position), self::wip($wipLimit));

        $this->changed($actor, $workflow, 'status_added', ['status_id' => $statusId, 'category' => $category]);

        return $statusId;
    }

    /**
     * @param  array{name?: string, position?: int, wip_limit?: int|null}  $changes
     */
    public function updateStatus(Authenticatable $actor, string $projectId, int $expectedVersion, string $statusId, array $changes): void
    {
        $workflow = $this->begin($actor, $projectId, $expectedVersion);
        $status = $this->workflows->findStatus($workflow['workflow_id'], $statusId);
        if ($status === null || $status['archived_at'] !== null) {
            throw WorkflowNotFound::make();
        }
        $columns = [];
        if (array_key_exists('name', $changes)) {
            $columns['name'] = self::name($changes['name']);
        }
        if (array_key_exists('position', $changes)) {
            $columns['position'] = self::position($changes['position']);
        }
        if (array_key_exists('wip_limit', $changes)) {
            // New items enter the initial status without a transition: no WIP there (review M2).
            if ($status['is_initial'] && $changes['wip_limit'] !== null) {
                throw InputRejected::because('The initial status cannot have a WIP limit.');
            }
            $columns['wip_limit'] = self::wip($changes['wip_limit']);
        }
        if ($columns === []) {
            throw InputRejected::because('Nothing to change.');
        }
        $this->workflows->updateStatus($statusId, $columns);
        $this->changed($actor, $workflow, 'status_updated', ['status_id' => $statusId, 'fields' => array_keys($columns)]);
    }

    public function archiveStatus(Authenticatable $actor, string $projectId, int $expectedVersion, string $statusId): void
    {
        $workflow = $this->begin($actor, $projectId, $expectedVersion);
        // FOR UPDATE: waits for in-flight moves into this status (they hold FOR SHARE).
        $status = $this->workflows->findStatus($workflow['workflow_id'], $statusId, forUpdate: true);
        if ($status === null || $status['archived_at'] !== null) {
            throw WorkflowNotFound::make();
        }
        if ($status['is_initial']) {
            throw WorkflowRuleViolation::because(WorkflowRuleViolation::INITIAL);
        }
        if ($this->usage->hasItems($statusId)) {
            throw WorkflowRuleViolation::because(WorkflowRuleViolation::IN_USE);
        }
        if ($status['category'] === 'done') {
            $activeDone = array_filter($this->workflows->statuses($workflow['workflow_id'], includeArchived: false), fn (array $s) => $s['category'] === 'done');
            if (count($activeDone) <= 1) {
                throw WorkflowRuleViolation::because(WorkflowRuleViolation::LAST_DONE);
            }
        }
        $this->workflows->updateStatus($statusId, ['archived' => true]);
        $this->changed($actor, $workflow, 'status_archived', ['status_id' => $statusId]);
    }

    public function addTransition(Authenticatable $actor, string $projectId, int $expectedVersion, string $from, string $to, bool $requiresReason, bool $checkBlockers, string $permission): string
    {
        $workflow = $this->begin($actor, $projectId, $expectedVersion);
        $source = $this->workflows->findStatus($workflow['workflow_id'], $from);
        $target = $this->workflows->findStatus($workflow['workflow_id'], $to);
        if ($source === null || $target === null || $source['archived_at'] !== null || $target['archived_at'] !== null || $from === $to) {
            throw WorkflowRuleViolation::because(WorkflowRuleViolation::STATUS);
        }
        $this->requireAllowedPermission($permission, $target['category']);
        if (count($this->workflows->transitions($workflow['workflow_id'])) >= self::MAX_TRANSITIONS) {
            throw WorkflowRuleViolation::because(WorkflowRuleViolation::LIMIT);
        }
        $transitionId = $this->workflows->addTransition($workflow['workflow_id'], $from, $to, $requiresReason, $checkBlockers, $permission);
        $this->changed($actor, $workflow, 'transition_added', ['transition_id' => $transitionId, 'from_status_id' => $from, 'to_status_id' => $to]);

        return $transitionId;
    }

    /**
     * @param  array{requires_reason?: bool, check_blockers?: bool, required_permission?: string}  $changes
     */
    public function updateTransition(Authenticatable $actor, string $projectId, int $expectedVersion, string $transitionId, array $changes, bool $disable = false): void
    {
        $workflow = $this->begin($actor, $projectId, $expectedVersion);
        $transition = $this->workflows->findTransition($workflow['workflow_id'], $transitionId);
        if ($transition === null || $transition['disabled_at'] !== null) {
            throw WorkflowNotFound::make();
        }
        if (array_key_exists('required_permission', $changes)) {
            $target = $this->workflows->findStatus($workflow['workflow_id'], $transition['to_status_id']) ?? throw WorkflowNotFound::make();
            $this->requireAllowedPermission($changes['required_permission'], $target['category']);
        }
        if ($changes === [] && ! $disable) {
            throw InputRejected::because('Nothing to change.');
        }
        $this->workflows->updateTransition($transitionId, $changes + ($disable ? ['disabled' => true] : []));
        $this->changed($actor, $workflow, $disable ? 'transition_disabled' : 'transition_updated', ['transition_id' => $transitionId, 'fields' => array_keys($changes)]);
    }

    /**
     * @return array{project_id: string, workflow_id: string, version: int}
     */
    private function begin(Authenticatable $actor, string $projectId, int $expectedVersion): array
    {
        $workflow = $this->workflowFor($actor, $projectId, forChange: true);
        if ($workflow['version'] !== $expectedVersion || ! $this->workflows->bumpVersion($workflow['workflow_id'], $expectedVersion)) {
            throw WorkflowVersionConflict::make();
        }

        return $workflow;
    }

    /**
     * @param  array{project_id: string, workflow_id: string, version: int}  $workflow
     * @param  array<string, mixed>  $change
     */
    private function changed(Authenticatable $actor, array $workflow, string $operation, array $change): void
    {
        $this->events->record(
            new DomainEvent('workflow.updated', 1, 'workflow', $workflow['workflow_id'], [
                'project_id' => $workflow['project_id'], 'version' => $workflow['version'] + 1, 'operation' => $operation,
            ] + $change),
            ActorReference::human((string) $actor->getAuthIdentifier()),
        );
    }

    /** The configuration only restricts: an allowlisted permission, and approval into done. */
    private function requireAllowedPermission(string $permission, string $targetCategory): void
    {
        if (! in_array($permission, self::PERMISSIONS, true)) {
            throw InputRejected::because('Invalid permission.');
        }
        if ($targetCategory === 'done' && $permission !== 'work.approve') {
            throw WorkflowRuleViolation::because(WorkflowRuleViolation::DONE_APPROVAL);
        }
    }

    private static function name(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 60) {
            throw InputRejected::because('Status name must be 1-60 characters.');
        }

        return $name;
    }

    private static function position(int $position): int
    {
        if ($position < 0 || $position > 1000) {
            throw InputRejected::because('Position must be between 0 and 1000.');
        }

        return $position;
    }

    private static function wip(?int $limit): ?int
    {
        if ($limit !== null && ($limit < 1 || $limit > 1000)) {
            throw InputRejected::because('WIP limit must be between 1 and 1000.');
        }

        return $limit;
    }
}
