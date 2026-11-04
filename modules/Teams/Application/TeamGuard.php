<?php

namespace Modules\Teams\Application;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Access\Contracts\Permission;
use Modules\Access\Contracts\Scope;
use Modules\IdentityTenancy\Contracts\CurrentMembership;
use Modules\Platform\Contracts\Tenancy\OrganizationId;
use Modules\Platform\Contracts\Tenancy\TenantContext;
use Modules\Platform\Contracts\Tenancy\TenantContextRequired;
use Modules\Teams\Contracts\HierarchyViolation;
use Modules\Teams\Contracts\TeamNotFound;
use Modules\Teams\Contracts\TeamOperationDenied;
use Modules\Teams\Infrastructure\TeamStore;

/**
 * Authorization for team management (D5, D14, D15). teams.manage is checked
 * within the team scope: organization-level holders manage every team; a
 * lead manages their team and its subteams. A team the actor can neither
 * manage nor belongs to is "not found" (404); one the actor belongs to but
 * cannot manage is "denied" (403).
 */
final class TeamGuard
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly CurrentMembership $memberships,
        private readonly Gate $gate,
        private readonly TeamStore $teams,
    ) {}

    /**
     * @return array{OrganizationId, string} organization and actor membership id
     */
    public function context(Authenticatable $actor): array
    {
        $organization = $this->tenant->current() ?? throw TenantContextRequired::forOperation('Managing teams');
        $membershipId = $this->memberships->idFor($actor) ?? throw TeamOperationDenied::because('No active membership.');

        return [$organization, $membershipId];
    }

    /** Root teams need the organization-level permission; subteams need to manage the parent. */
    public function requireCreate(Authenticatable $actor, string $actorMembershipId, ?string $parentTeamId): void
    {
        if ($parentTeamId === null) {
            if (! $this->managesOrganizationTeams($actor)) {
                throw TeamOperationDenied::because('teams.manage is required to create root teams.');
            }

            return;
        }

        $this->requireManageDestination($actor, $actorMembershipId, $parentTeamId);
    }

    /**
     * @return array{id: string, status: string, parent_team_id: string|null}
     */
    public function requireManage(Authenticatable $actor, string $actorMembershipId, string $teamId): array
    {
        $team = $this->teams->find($teamId) ?? throw TeamNotFound::make();

        if ($this->manages($actor, $teamId)) {
            return $team;
        }

        if ($this->teams->isActiveMember($teamId, $actorMembershipId)) {
            throw TeamOperationDenied::because('teams.manage is required.');
        }

        throw TeamNotFound::make();
    }

    /**
     * A new parent must be managed by the actor too (moving a subtree needs
     * both source and destination). An unmanageable parent the actor does
     * not belong to is reported like a missing one (422).
     */
    public function requireManageDestination(Authenticatable $actor, string $actorMembershipId, string $parentTeamId): void
    {
        if ($this->teams->find($parentTeamId) === null) {
            throw HierarchyViolation::because(HierarchyViolation::PARENT);
        }

        if ($this->manages($actor, $parentTeamId)) {
            return;
        }

        if ($this->teams->isActiveMember($parentTeamId, $actorMembershipId)) {
            throw TeamOperationDenied::because('teams.manage over the destination team is required.');
        }

        throw HierarchyViolation::because(HierarchyViolation::PARENT);
    }

    /** Organization-level only: appointing leads and creating root teams (D14). */
    public function managesOrganizationTeams(Authenticatable $actor): bool
    {
        return $this->gate->forUser($actor)->allows(Permission::TeamsManage->value);
    }

    private function manages(Authenticatable $actor, string $teamId): bool
    {
        return $this->gate->forUser($actor)->allows(Permission::TeamsManage->value, [Scope::team($teamId)]);
    }
}
