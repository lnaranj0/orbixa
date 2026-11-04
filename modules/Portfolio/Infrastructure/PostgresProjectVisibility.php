<?php

namespace Modules\Portfolio\Infrastructure;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Access\Contracts\Authorizer;
use Modules\Access\Contracts\Permission;
use Modules\IdentityTenancy\Contracts\CurrentMembership;
use Modules\Portfolio\Contracts\AccessibleProjects;
use Modules\Portfolio\Contracts\ProjectVisibility;
use Modules\Teams\Contracts\TeamDirectory;

final class PostgresProjectVisibility implements ProjectVisibility
{
    public function __construct(
        private readonly CurrentMembership $memberships,
        private readonly Authorizer $authorizer,
        private readonly TeamDirectory $teams,
        private readonly ProjectStore $projects,
    ) {}

    public function accessibleProjects(Authenticatable $actor): AccessibleProjects
    {
        $membershipId = $this->memberships->idFor($actor);

        return $membershipId === null ? AccessibleProjects::only([]) : $this->accessibleTo($membershipId);
    }

    public function canView(Authenticatable $actor, string $projectId): bool
    {
        $membershipId = $this->memberships->idFor($actor);

        return $membershipId !== null && $this->canMembershipView($membershipId, $projectId);
    }

    public function canMembershipView(string $membershipId, string $projectId): bool
    {
        if (! ProjectStore::isUuid($projectId) || ! ProjectStore::isUuid($membershipId)) {
            return false;
        }

        $accessible = $this->accessibleTo($membershipId);

        // "Everything" means every project of the ACTIVE organization: the id
        // must still exist under RLS, or a foreign UUID would pass.
        return $accessible->all ? $this->projects->find($projectId) !== null : $accessible->includes($projectId);
    }

    private function accessibleTo(string $membershipId): AccessibleProjects
    {
        // (a) organization-level projects.manage (owner/admin), restricted included.
        if ($this->authorizer->membershipHas($membershipId, Permission::ProjectsManage)) {
            return AccessibleProjects::everything();
        }

        // (b) direct membership, (c) direct link of an active team to a standard project.
        return AccessibleProjects::only(
            $this->projects->visibleIdsFor($membershipId, $this->teams->activeTeamIdsOf($membershipId))
        );
    }
}
