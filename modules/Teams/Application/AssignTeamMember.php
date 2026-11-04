<?php

namespace Modules\Teams\Application;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\IdentityTenancy\Contracts\MembershipLookup;
use Modules\IdentityTenancy\Contracts\MembershipReferenceInvalid;
use Modules\Platform\Contracts\Events\ActorReference;
use Modules\Platform\Contracts\Events\DomainEvent;
use Modules\Platform\Contracts\Events\DomainEventRecorder;
use Modules\Platform\Contracts\Validation\InputRejected;
use Modules\Teams\Contracts\HierarchyViolation;
use Modules\Teams\Contracts\TeamOperationDenied;
use Modules\Teams\Infrastructure\TeamStore;

/**
 * Adds an active organization member to a team (D6, D13, D14): nobody adds
 * themselves, leads are appointed only with the organization-level
 * permission (also to demote one), and the membership is locked FOR SHARE
 * so a concurrent revocation cannot interleave. Idempotent for the same
 * role; a role change closes the old row and opens a new one.
 */
final class AssignTeamMember
{
    public function __construct(
        private readonly TeamGuard $guard,
        private readonly TeamStore $teams,
        private readonly MembershipLookup $memberships,
        private readonly DomainEventRecorder $events,
    ) {}

    public function handle(Authenticatable $actor, string $teamId, string $membershipId, string $role = 'member'): void
    {
        if (! in_array($role, ['member', 'lead'], true)) {
            throw InputRejected::because('Role must be member or lead.');
        }

        [, $actorMembershipId] = $this->guard->context($actor);
        $team = $this->guard->requireManage($actor, $actorMembershipId, $teamId);

        if ($membershipId === $actorMembershipId) {
            throw TeamOperationDenied::because('Nobody can add themselves to a team.');
        }
        if ($team['status'] !== 'active') {
            throw HierarchyViolation::because(HierarchyViolation::ARCHIVED);
        }
        if (! TeamStore::isUuid($membershipId) || ! $this->memberships->lockActive($membershipId)) {
            throw MembershipReferenceInvalid::make();
        }

        $current = $this->teams->lockMemberRole($teamId, $membershipId);
        if ($current === $role) {
            return;
        }
        if (($role === 'lead' || $current === 'lead') && ! $this->guard->managesOrganizationTeams($actor)) {
            throw TeamOperationDenied::because('Appointing or demoting a lead requires the organization-level teams.manage.');
        }

        $by = ActorReference::human((string) $actor->getAuthIdentifier());
        if ($current !== null) {
            $this->teams->removeMember($teamId, $membershipId, $actorMembershipId, 'removed');
            $this->events->record(new DomainEvent('team.member.removed', 1, 'team', $teamId, ['membership_id' => $membershipId]), $by);
        }

        $this->teams->addMember($teamId, $membershipId, $role, $actorMembershipId);
        $this->events->record(new DomainEvent('team.member.assigned', 1, 'team', $teamId, ['membership_id' => $membershipId, 'role' => $role]), $by);
    }
}
