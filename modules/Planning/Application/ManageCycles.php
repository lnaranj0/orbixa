<?php

namespace Modules\Planning\Application;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Access\Contracts\Permission;
use Modules\Access\Contracts\Scope;
use Modules\IdentityTenancy\Contracts\CurrentMembership;
use Modules\IdentityTenancy\Contracts\MembershipLookup;
use Modules\Planning\Contracts\CycleNotFound;
use Modules\Planning\Contracts\PlanningOperationDenied;
use Modules\Planning\Contracts\PlanningRuleViolation;
use Modules\Planning\Infrastructure\CycleStore;
use Modules\Platform\Contracts\Events\ActorReference;
use Modules\Platform\Contracts\Events\DomainEvent;
use Modules\Platform\Contracts\Events\DomainEventRecorder;
use Modules\Platform\Contracts\Tenancy\TenantContext;
use Modules\Platform\Contracts\Tenancy\TenantContextRequired;
use Modules\Platform\Contracts\Validation\InputRejected;
use Modules\Portfolio\Contracts\ProjectDirectory;
use Modules\Portfolio\Contracts\ProjectReference;
use Modules\Work\Contracts\Planning\ItemCategories;

/**
 * Optional cycles (05 D-B, D-D, P9). Reading needs a visible project;
 * changes need planning.manage over it (organization role or project
 * manager). Continuous projects have no cycles; items join at most one open
 * cycle of their own project; closing records each item's outcome and can
 * carry unfinished items to a next cycle.
 *
 * @phpstan-import-type CycleRow from CycleStore
 */
final class ManageCycles
{
    public const MODES = ['continuous', 'sprint', 'hybrid'];

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly CurrentMembership $memberships,
        private readonly MembershipLookup $lookup,
        private readonly Gate $gate,
        private readonly ProjectDirectory $projects,
        private readonly CycleStore $cycles,
        private readonly ItemCategories $items,
        private readonly DomainEventRecorder $events,
    ) {}

    public function project(Authenticatable $actor, string $projectId): ProjectReference
    {
        $this->tenant->current() ?? throw TenantContextRequired::forOperation('Planning');
        $project = CycleStore::isUuid($projectId) ? $this->projects->reference($actor, $projectId) : null;

        return $project ?? throw CycleNotFound::make();
    }

    /**
     * @return array{0: CycleRow, 1: ProjectReference}
     */
    public function cycle(Authenticatable $actor, string $cycleId): array
    {
        $cycle = $this->cycles->find($cycleId) ?? throw CycleNotFound::make();

        return [$cycle, $this->project($actor, $cycle['project_id'])];
    }

    /**
     * Authorizes first, then locks: nobody without planning.manage can hold
     * the cycle row (review B12).
     *
     * @return array{0: CycleRow, 1: ProjectReference, 2: string} cycle, project, actor membership
     */
    private function cycleForChange(Authenticatable $actor, string $cycleId): array
    {
        [, $project] = $this->cycle($actor, $cycleId);
        $by = $this->requireManage($actor, $project);
        $cycle = $this->cycles->find($cycleId, forUpdate: true) ?? throw CycleNotFound::make();

        return [$cycle, $project, $by];
    }

    public function setMode(Authenticatable $actor, string $projectId, string $mode): void
    {
        $project = $this->project($actor, $projectId);
        $this->requireManage($actor, $project);
        if (! in_array($mode, self::MODES, true)) {
            throw InputRejected::because('Invalid planning mode.');
        }
        $this->cycles->setMode($project->id, $mode);
    }

    public function create(Authenticatable $actor, string $projectId, string $name, ?string $goal, string $startsOn, string $endsOn, ?string $capacity): string
    {
        $project = $this->project($actor, $projectId);
        $by = $this->requireManage($actor, $project);
        if ($this->cycles->mode($project->id) === 'continuous') {
            throw PlanningRuleViolation::because(PlanningRuleViolation::CONTINUOUS);
        }
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 80) {
            throw InputRejected::because('Cycle name must be 1-80 characters.');
        }
        $goal = $goal === null || trim($goal) === '' ? null : trim($goal);
        if ($goal !== null && mb_strlen($goal) > 500) {
            throw InputRejected::because('Goal must be at most 500 characters.');
        }
        foreach ([$startsOn, $endsOn] as $date) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
                throw InputRejected::because('Dates must be YYYY-MM-DD.');
            }
        }
        if ($capacity !== null && (! is_numeric($capacity) || (float) $capacity < 0 || (float) $capacity > 999999)) {
            throw InputRejected::because('Capacity must be a non-negative number.');
        }

        $cycleId = $this->cycles->create($project->id, $name, $goal, $startsOn, $endsOn, $capacity, $by);
        $this->event($actor, 'planning.cycle.created', $cycleId, ['project_id' => $project->id]);

        return $cycleId;
    }

    public function start(Authenticatable $actor, string $cycleId): void
    {
        [$cycle, $project] = $this->cycleForChange($actor, $cycleId);
        if ($cycle['status'] !== 'planned') {
            throw PlanningRuleViolation::because(PlanningRuleViolation::STATE);
        }
        $this->cycles->setStatus($cycleId, 'active');
        $this->event($actor, 'planning.cycle.started', $cycleId, ['project_id' => $project->id]);
    }

    public function addItem(Authenticatable $actor, string $cycleId, string $itemId, bool $committed = true): void
    {
        [$cycle, $project] = $this->cycle($actor, $cycleId);
        $by = $this->requireManage($actor, $project);
        if ($cycle['status'] === 'closed') {
            throw PlanningRuleViolation::because(PlanningRuleViolation::CLOSED);
        }
        if (! CycleStore::isUuid($itemId)) {
            throw PlanningRuleViolation::because(PlanningRuleViolation::ITEM);
        }
        // Same project and organization are enforced by the composite foreign key.
        $this->cycles->addItem($project->id, $cycleId, $itemId, $committed, null, $by);
        $this->event($actor, 'planning.cycle.item_added', $cycleId, ['project_id' => $project->id, 'item_id' => $itemId]);
    }

    public function removeItem(Authenticatable $actor, string $cycleId, string $itemId): void
    {
        [, $project] = $this->cycle($actor, $cycleId);
        $by = $this->requireManage($actor, $project);
        if (! CycleStore::isUuid($itemId) || $this->cycles->endItem($cycleId, $itemId, 'removed', $by) === 0) {
            throw CycleNotFound::make();
        }
        $this->event($actor, 'planning.cycle.item_removed', $cycleId, ['project_id' => $project->id, 'item_id' => $itemId]);
    }

    /**
     * Closes the cycle: done items are delivered, the rest spilled; spilled
     * items move to $carryTo (a planned or active cycle of the same project)
     * when given.
     *
     * @return array{delivered: int, spilled: int}
     */
    public function close(Authenticatable $actor, string $cycleId, ?string $carryTo = null): array
    {
        [$cycle, $project, $by] = $this->cycleForChange($actor, $cycleId);
        if ($cycle['status'] === 'closed') {
            throw PlanningRuleViolation::because(PlanningRuleViolation::CLOSED);
        }
        $next = null;
        if ($carryTo !== null) {
            $next = $this->cycles->find($carryTo);
            if ($next === null || $next['project_id'] !== $project->id || $next['status'] === 'closed' || $next['id'] === $cycleId) {
                throw PlanningRuleViolation::because(PlanningRuleViolation::STATE);
            }
        }

        $open = $this->cycles->items($cycleId, openOnly: true);
        $categories = $this->items->of(array_column($open, 'item_id'));
        $counts = ['delivered' => 0, 'spilled' => 0];
        foreach ($open as $membership) {
            $outcome = ($categories[$membership['item_id']] ?? null) === 'done' ? 'delivered' : 'spilled';
            $this->cycles->endItem($cycleId, $membership['item_id'], $outcome, $by);
            $counts[$outcome]++;
            if ($outcome === 'spilled' && $next !== null && ($categories[$membership['item_id']] ?? null) !== 'canceled') {
                $this->cycles->addItem($project->id, $next['id'], $membership['item_id'], $membership['committed'], $cycleId, $by);
                $this->event($actor, 'planning.cycle.item_added', $next['id'], ['project_id' => $project->id, 'item_id' => $membership['item_id'], 'carried_from_cycle_id' => $cycleId]);
            }
        }
        $this->cycles->setStatus($cycleId, 'closed');
        $this->event($actor, 'planning.cycle.closed', $cycleId, ['project_id' => $project->id, 'carried_to_cycle_id' => $next['id'] ?? null] + $counts);

        return $counts;
    }

    /** @return string actor membership id, locked until commit */
    private function requireManage(Authenticatable $actor, ProjectReference $project): string
    {
        $membershipId = $this->memberships->idFor($actor);
        if ($membershipId === null || ! $this->lookup->lockActive($membershipId)) {
            throw PlanningOperationDenied::because('No active membership.');
        }
        if (! $this->gate->forUser($actor)->allows(Permission::PlanningManage->value, [Scope::project($project->id)])) {
            throw PlanningOperationDenied::because('planning.manage over the project is required.');
        }
        if ($project->isArchived()) {
            throw InputRejected::because('The project is archived.');
        }

        return $membershipId;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function event(Authenticatable $actor, string $name, string $cycleId, array $payload): void
    {
        $this->events->record(new DomainEvent($name, 1, 'cycle', $cycleId, $payload), ActorReference::human((string) $actor->getAuthIdentifier()));
    }
}
