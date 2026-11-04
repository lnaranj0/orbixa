<?php

namespace Modules\Work\Application;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Access\Contracts\Authorizer;
use Modules\Access\Contracts\Permission;
use Modules\IdentityTenancy\Contracts\MembershipLookup;
use Modules\IdentityTenancy\Contracts\MembershipReferenceInvalid;
use Modules\Platform\Contracts\Events\ActorReference;
use Modules\Platform\Contracts\Events\DomainEvent;
use Modules\Platform\Contracts\Events\DomainEventRecorder;
use Modules\Platform\Contracts\Validation\InputRejected;
use Modules\Portfolio\Contracts\ProjectVisibility;
use Modules\Work\Contracts\WorkOperationDenied;
use Modules\Work\Contracts\WorkRelationNotFound;
use Modules\Work\Contracts\WorkRuleViolation;
use Modules\Work\Infrastructure\ItemStore;

/**
 * Owner and collaborators of a work item (W7). The assignee must be an
 * active member (locked FOR SHARE against a concurrent revocation) who can
 * see the project; otherwise the same 422 as an unknown membership. Nobody
 * assigns themselves. A new owner replaces the previous one; changing a
 * person's role closes the old row and opens a new one.
 */
final class AssignWorkItem
{
    private const MAX_ASSIGNEES = 50;

    public function __construct(
        private readonly WorkGuard $guard,
        private readonly ItemStore $items,
        private readonly MembershipLookup $memberships,
        private readonly ProjectVisibility $visibility,
        private readonly Authorizer $authorizer,
        private readonly DomainEventRecorder $events,
    ) {}

    public function assign(Authenticatable $actor, string $itemId, string $membershipId, string $role = 'collaborator'): void
    {
        if (! in_array($role, ['owner', 'collaborator'], true)) {
            throw InputRejected::because('Role must be owner or collaborator.');
        }
        $actorMembershipId = $this->guard->context($actor);
        [$item, $project] = $this->guard->item($actor, $itemId);
        $this->guard->requireChange($actor, $actorMembershipId, $item, $project, Permission::WorkAssign);

        if ($membershipId === $actorMembershipId) {
            throw WorkOperationDenied::because('Nobody can assign themselves.');
        }
        // Assignees must hold a working role and see the project; anything
        // else (viewer, reviewer, outsider, revoked, foreign) is one 422.
        if (! ItemStore::isUuid($membershipId) || ! $this->memberships->lockActive($membershipId)
            || ! $this->authorizer->membershipHas($membershipId, Permission::WorkCreate)
            || ! $this->visibility->canMembershipView($membershipId, $project->id)) {
            throw MembershipReferenceInvalid::make();
        }
        $this->items->lockItem($itemId);

        $current = $this->items->lockAssignmentRole($itemId, $membershipId);
        if ($current === $role) {
            return;
        }
        $changes = ['membership_id' => $membershipId, 'role' => $role];
        if ($current !== null) {
            $this->items->closeAssignment($itemId, $membershipId, $actorMembershipId, 'replaced');
        }
        if ($role === 'owner') {
            $previous = $this->items->lockOwner($itemId);
            if ($previous !== null) {
                $this->items->closeAssignment($itemId, $previous, $actorMembershipId, 'replaced');
                $changes['replaced_owner_membership_id'] = $previous;
            }
        }
        if ($current === null && $role === 'collaborator' && count($this->items->assignmentsOf($itemId)) >= self::MAX_ASSIGNEES) {
            throw WorkRuleViolation::because(WorkRuleViolation::ASSIGNEE_LIMIT);
        }
        if (! $this->items->assign($itemId, $membershipId, $role, $actorMembershipId)) {
            return;
        }

        $this->items->recordActivity($itemId, 'assigned', $actorMembershipId, $changes);
        $this->events->record(
            new DomainEvent('work.item.assigned', 1, 'work_item', $itemId, ['project_id' => $project->id] + $changes),
            ActorReference::human((string) $actor->getAuthIdentifier()),
        );
    }

    public function unassign(Authenticatable $actor, string $itemId, string $membershipId): void
    {
        $actorMembershipId = $this->guard->context($actor);
        [$item, $project] = $this->guard->item($actor, $itemId);
        $this->guard->requireChange($actor, $actorMembershipId, $item, $project, Permission::WorkAssign);

        if (! ItemStore::isUuid($membershipId) || $this->items->closeAssignment($itemId, $membershipId, $actorMembershipId, 'removed') === 0) {
            throw WorkRelationNotFound::make();
        }

        $this->items->recordActivity($itemId, 'unassigned', $actorMembershipId, ['membership_id' => $membershipId]);
        $this->events->record(
            new DomainEvent('work.item.updated', 1, 'work_item', $itemId, ['project_id' => $project->id, 'fields' => ['assignments']]),
            ActorReference::human((string) $actor->getAuthIdentifier()),
        );
    }
}
