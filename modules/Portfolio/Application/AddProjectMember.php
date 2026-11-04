<?php

namespace Modules\Portfolio\Application;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Access\Contracts\Authorizer;
use Modules\Access\Contracts\Permission;
use Modules\IdentityTenancy\Contracts\MembershipLookup;
use Modules\IdentityTenancy\Contracts\MembershipReferenceInvalid;
use Modules\Platform\Contracts\Events\ActorReference;
use Modules\Platform\Contracts\Events\DomainEvent;
use Modules\Platform\Contracts\Events\DomainEventRecorder;
use Modules\Platform\Contracts\Validation\InputRejected;
use Modules\Portfolio\Contracts\ProjectOperationDenied;
use Modules\Portfolio\Infrastructure\ProjectStore;

/**
 * Adds an active organization member to a project (D6, D13): nobody adds
 * themselves, managers are appointed (or demoted) only with the
 * organization-level projects.manage, and the membership is locked FOR SHARE
 * against a concurrent revocation. Idempotent for the same role; a role
 * change closes the old row and opens a new one, so history stays complete.
 */
final class AddProjectMember
{
    public function __construct(
        private readonly ProjectGuard $guard,
        private readonly ProjectStore $projects,
        private readonly MembershipLookup $memberships,
        private readonly DomainEventRecorder $events,
        private readonly Authorizer $authorizer,
    ) {}

    public function handle(Authenticatable $actor, string $projectId, string $membershipId, string $role = 'member'): void
    {
        if (! in_array($role, ['member', 'manager'], true)) {
            throw InputRejected::because('Role must be member or manager.');
        }

        $actorMembershipId = $this->guard->context($actor);
        $this->guard->requireManage($actor, $projectId);

        if ($membershipId === $actorMembershipId) {
            throw ProjectOperationDenied::because('Nobody can add themselves to a project.');
        }
        if (! ProjectStore::isUuid($membershipId) || ! $this->memberships->lockActive($membershipId)) {
            throw MembershipReferenceInvalid::make();
        }
        // 05 review M4: project.manager grants approve/edit/planning in scope;
        // only working roles (organization-level work.create) may hold it.
        if ($role === 'manager' && ! $this->authorizer->membershipHas($membershipId, Permission::WorkCreate)) {
            throw MembershipReferenceInvalid::make();
        }

        $current = $this->projects->lockMemberRole($projectId, $membershipId);
        if ($current === $role) {
            return;
        }
        if (($role === 'manager' || $current === 'manager') && ! $this->guard->managesOrganizationProjects($actor)) {
            throw ProjectOperationDenied::because('Appointing or demoting a manager requires the organization-level projects.manage.');
        }

        $by = ActorReference::human((string) $actor->getAuthIdentifier());
        if ($current !== null) {
            $this->projects->removeMember($projectId, $membershipId, $actorMembershipId);
            $this->events->record(new DomainEvent('project.member.removed', 1, 'project', $projectId, ['membership_id' => $membershipId]), $by);
        }

        $this->projects->addMember($projectId, $membershipId, $role, $actorMembershipId);
        $this->events->record(new DomainEvent('project.member.added', 1, 'project', $projectId, ['membership_id' => $membershipId, 'role' => $role]), $by);
    }
}
