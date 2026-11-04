<?php

namespace Modules\Portfolio\Application;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Access\Contracts\Permission;
use Modules\Access\Contracts\Scope;
use Modules\IdentityTenancy\Contracts\CurrentMembership;
use Modules\IdentityTenancy\Contracts\MembershipLookup;
use Modules\Platform\Contracts\Tenancy\TenantContext;
use Modules\Platform\Contracts\Tenancy\TenantContextRequired;
use Modules\Portfolio\Contracts\ProjectNotFound;
use Modules\Portfolio\Contracts\ProjectOperationDenied;
use Modules\Portfolio\Contracts\ProjectRuleViolation;
use Modules\Portfolio\Contracts\ProjectVisibility;
use Modules\Portfolio\Infrastructure\ProjectStore;
use Modules\Teams\Contracts\TeamDirectory;

/**
 * Authorization for project management (D4, D5). A project the actor cannot
 * see is "not found" (404) whatever the reason; a visible project the actor
 * cannot manage is "denied" (403).
 */
final class ProjectGuard
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly CurrentMembership $memberships,
        private readonly Gate $gate,
        private readonly ProjectVisibility $visibility,
        private readonly TeamDirectory $teams,
        private readonly MembershipLookup $lookup,
        private readonly ProjectStore $projects,
    ) {}

    /** @return string actor membership id */
    public function context(Authenticatable $actor): string
    {
        $this->tenant->current() ?? throw TenantContextRequired::forOperation('Managing projects');

        $membershipId = $this->memberships->idFor($actor) ?? throw ProjectOperationDenied::because('No active membership.');

        // Hold the actor's authority until commit: a concurrent revocation or
        // demotion waits for this operation instead of racing it.
        if (! $this->lookup->lockActive($membershipId)) {
            throw ProjectOperationDenied::because('No active membership.');
        }
        $this->projects->lockManagerRolesOf($membershipId);

        return $membershipId;
    }

    public function requireCreate(Authenticatable $actor): void
    {
        if (! $this->gate->forUser($actor)->allows(Permission::ProjectsCreate->value)) {
            throw ProjectOperationDenied::because('projects.create is required.');
        }
    }

    public function requireManage(Authenticatable $actor, string $projectId): void
    {
        if (! ProjectStore::isUuid($projectId) || ! $this->visibility->canView($actor, $projectId)) {
            throw ProjectNotFound::make();
        }

        if (! $this->gate->forUser($actor)->allows(Permission::ProjectsManage->value, [Scope::project($projectId)])) {
            throw ProjectOperationDenied::because('projects.manage is required.');
        }
    }

    /** Organization-level only: appointing or removing managers (D6). */
    public function managesOrganizationProjects(Authenticatable $actor): bool
    {
        return $this->gate->forUser($actor)->allows(Permission::ProjectsManage->value);
    }

    /**
     * Linking needs teams.manage over the team too (D6). A team the actor
     * belongs to but cannot manage is "denied" (403); a missing, foreign,
     * archived or otherwise unmanageable team is one generic 422.
     */
    public function requireLinkableTeam(Authenticatable $actor, string $actorMembershipId, string $teamId): void
    {
        if (! ProjectStore::isUuid($teamId) || ! $this->teams->isActive($teamId)) {
            throw ProjectRuleViolation::because(ProjectRuleViolation::TEAM);
        }

        if ($this->gate->forUser($actor)->allows(Permission::TeamsManage->value, [Scope::team($teamId)])) {
            return;
        }

        if (in_array($teamId, $this->teams->activeTeamIdsOf($actorMembershipId), true)) {
            throw ProjectOperationDenied::because('teams.manage over the team is required.');
        }

        throw ProjectRuleViolation::because(ProjectRuleViolation::TEAM);
    }
}
