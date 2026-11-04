<?php

namespace Modules\Work\Application;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Access\Contracts\Authorizer;
use Modules\Access\Contracts\Permission;
use Modules\Access\Contracts\Scope;
use Modules\IdentityTenancy\Contracts\CurrentMembership;
use Modules\IdentityTenancy\Contracts\MembershipLookup;
use Modules\Platform\Contracts\Tenancy\TenantContext;
use Modules\Platform\Contracts\Tenancy\TenantContextRequired;
use Modules\Portfolio\Contracts\ProjectDirectory;
use Modules\Portfolio\Contracts\ProjectReference;
use Modules\Work\Contracts\ReadOnlyItemAccess;
use Modules\Work\Contracts\WorkItemNotFound;
use Modules\Work\Contracts\WorkOperationDenied;
use Modules\Work\Contracts\WorkRuleViolation;
use Modules\Work\Infrastructure\ItemStore;

/**
 * Authorization for work items (W5, W6, W13). An item is visible exactly
 * when its project is (ProjectDirectory::reference); otherwise it is "not
 * found" (404). A visible item the actor cannot change is "denied" (403).
 *
 * @phpstan-import-type ItemRow from ItemStore
 */
final class WorkGuard
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly CurrentMembership $memberships,
        private readonly MembershipLookup $lookup,
        private readonly Gate $gate,
        private readonly ProjectDirectory $projects,
        private readonly ItemStore $items,
        private readonly Authorizer $authorizer,
        /** @var iterable<ReadOnlyItemAccess> */
        private readonly iterable $readOnlyAccess = [],
    ) {}

    /**
     * Actor membership id, locked until commit so a concurrent revocation
     * waits for this operation.
     */
    public function context(Authenticatable $actor): string
    {
        $this->tenant->current() ?? throw TenantContextRequired::forOperation('Managing work');
        $membershipId = $this->memberships->idFor($actor) ?? throw WorkOperationDenied::because('No active membership.');
        if (! $this->lookup->lockActive($membershipId)) {
            throw WorkOperationDenied::because('No active membership.');
        }

        return $membershipId;
    }

    /** Visible project or 404 (same answer for missing, foreign and hidden). */
    public function project(Authenticatable $actor, string $projectId): ProjectReference
    {
        if (! ItemStore::isUuid($projectId)) {
            throw WorkItemNotFound::make();
        }

        return $this->projects->reference($actor, $projectId) ?? throw WorkItemNotFound::make();
    }

    /**
     * @return array{0: ItemRow, 1: ProjectReference}
     */
    public function item(Authenticatable $actor, string $itemId): array
    {
        $item = $this->items->find($itemId) ?? throw WorkItemNotFound::make();
        $project = $this->projects->reference($actor, $item['project_id']) ?? throw WorkItemNotFound::make();

        return [$item, $project];
    }

    /**
     * The item for a WRITE: like item(), but an item the actor only reads
     * through a monitoring grant is "denied" (403, audited by the provider)
     * instead of "not found" (04 M2).
     *
     * @return array{0: ItemRow, 1: ProjectReference}
     */
    public function itemForChange(Authenticatable $actor, string $itemId): array
    {
        try {
            return $this->item($actor, $itemId);
        } catch (WorkItemNotFound $notFound) {
            if (ItemStore::isUuid($itemId)) {
                foreach ($this->readOnlyAccess as $provider) {
                    if ($provider->refuseWrite($actor, $itemId)) {
                        throw WorkOperationDenied::because('Read-only access.');
                    }
                }
            }

            throw $notFound;
        }
    }

    public function requireCreate(Authenticatable $actor, ProjectReference $project): void
    {
        if ($project->isArchived()) {
            throw WorkRuleViolation::because(WorkRuleViolation::ARCHIVED);
        }
        if (! $this->allowsInProject($actor, Permission::WorkCreate, $project->id)) {
            throw WorkOperationDenied::because('work.create is required.');
        }
    }

    /**
     * work.edit / work.transition: organization role, project manager, lead of
     * an associated team, or an active assignee of the item. work.assign:
     * the same without the assignee path.
     *
     * @param  ItemRow  $item
     */
    public function requireChange(Authenticatable $actor, string $actorMembershipId, array $item, ProjectReference $project, Permission $permission): void
    {
        if ($project->isArchived()) {
            throw WorkRuleViolation::because(WorkRuleViolation::ARCHIVED);
        }
        if ($this->allowsInProject($actor, $permission, $project->id)) {
            return;
        }
        // The assignee and team-lead paths only extend working roles (those
        // with organization-level work.create); viewers and reviewers never edit.
        if ($this->authorizer->membershipHas($actorMembershipId, Permission::WorkCreate)) {
            if ($permission !== Permission::WorkAssign && $this->items->isActiveAssignee($item['id'], $actorMembershipId)) {
                return;
            }
            if ($this->leadsAnAssociatedTeam($actor, $item['id'])) {
                return;
            }
        }

        throw WorkOperationDenied::because("{$permission->value} is required.");
    }

    /**
     * Same rule as requireChange, as a boolean for screens.
     *
     * @param  ItemRow  $item
     */
    public function canChange(Authenticatable $actor, string $actorMembershipId, array $item, ProjectReference $project, Permission $permission): bool
    {
        try {
            $this->requireChange($actor, $actorMembershipId, $item, $project, $permission);

            return true;
        } catch (WorkOperationDenied|WorkRuleViolation) {
            return false;
        }
    }

    public function canCreate(Authenticatable $actor, ProjectReference $project): bool
    {
        return ! $project->isArchived() && $this->allowsInProject($actor, Permission::WorkCreate, $project->id);
    }

    /** Project-level management of labels: organization role or project manager. */
    public function requireProjectEdit(Authenticatable $actor, ProjectReference $project): void
    {
        if ($project->isArchived()) {
            throw WorkRuleViolation::because(WorkRuleViolation::ARCHIVED);
        }
        if (! $this->allowsInProject($actor, Permission::WorkEdit, $project->id)) {
            throw WorkOperationDenied::because('work.edit over the project is required.');
        }
    }

    public function leadsAnAssociatedTeam(Authenticatable $actor, string $itemId): bool
    {
        foreach ($this->items->teamIdsOf($itemId) as $teamId) {
            if ($this->gate->forUser($actor)->allows(Permission::TeamsManage->value, [Scope::team($teamId)])) {
                return true;
            }
        }

        return false;
    }

    public function allowsInProject(Authenticatable $actor, Permission $permission, string $projectId): bool
    {
        return $this->gate->forUser($actor)->allows($permission->value, [Scope::project($projectId)]);
    }
}
