<?php

namespace Modules\Teams\Application;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\IdentityTenancy\Contracts\CurrentMembership;
use Modules\Platform\Contracts\Tenancy\OrganizationId;
use Modules\Platform\Contracts\Tenancy\TenantContext;
use Modules\Platform\Contracts\Tenancy\TenantContextRequired;
use Modules\Teams\Contracts\TeamNotFound;
use Modules\Teams\Contracts\TeamOperationDenied;
use Modules\Teams\Infrastructure\TeamStore;

/**
 * Authorization for team management (D5, D15): a team the actor can neither
 * manage nor belongs to is "not found" (404); a team the actor belongs to
 * but cannot manage is "denied" (403). Increment 4 adds the scoped leader
 * permission here.
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

    public function requireCreate(Authenticatable $actor): void
    {
        if (! $this->managesOrganizationTeams($actor)) {
            throw TeamOperationDenied::because('teams.manage is required.');
        }
    }

    /**
     * @return array{id: string, status: string, parent_team_id: string|null}
     */
    public function requireManage(Authenticatable $actor, string $actorMembershipId, string $teamId): array
    {
        $team = $this->teams->find($teamId) ?? throw TeamNotFound::make();

        if ($this->managesOrganizationTeams($actor)) {
            return $team;
        }

        if ($this->teams->isActiveMember($teamId, $actorMembershipId)) {
            throw TeamOperationDenied::because('teams.manage is required.');
        }

        throw TeamNotFound::make();
    }

    /** Appointing leads always requires the organization-level permission (D14). */
    public function managesOrganizationTeams(Authenticatable $actor): bool
    {
        return $this->gate->forUser($actor)->allows('teams.manage');
    }
}
