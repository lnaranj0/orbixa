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

        if ($membershipId === null) {
            return AccessibleProjects::only([]);
        }

        // (a) organization-level projects.manage (owner/admin), restricted included.
        if ($this->authorizer->can($actor, Permission::ProjectsManage)) {
            return AccessibleProjects::everything();
        }

        // (b) direct membership, (c) direct link of an active team to a standard project.
        return AccessibleProjects::only(
            $this->projects->visibleIdsFor($membershipId, $this->teams->activeTeamIdsOf($membershipId))
        );
    }

    public function canView(Authenticatable $actor, string $projectId): bool
    {
        if (! ProjectStore::isUuid($projectId)) {
            return false;
        }

        $accessible = $this->accessibleProjects($actor);

        // "Everything" means every project of the ACTIVE organization: the id
        // must still exist under RLS, or a foreign UUID would pass.
        return $accessible->all ? $this->projects->find($projectId) !== null : $accessible->includes($projectId);
    }
}
