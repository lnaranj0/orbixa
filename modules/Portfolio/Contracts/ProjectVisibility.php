<?php

namespace Modules\Portfolio\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The single project visibility predicate (D1, D2, D3). A project of the
 * active organization is visible when the actor:
 *  (a) holds organization-level projects.manage (owner/admin), or
 *  (b) is an active direct member of the project, or
 *  (c) is an active member of an active team linked DIRECTLY to a
 *      'standard' project (no inheritance through parents or subteams).
 * 'restricted' projects require (a) or (b).
 */
interface ProjectVisibility
{
    public function accessibleProjects(Authenticatable $actor): AccessibleProjects;

    public function canView(Authenticatable $actor, string $projectId): bool;

    /**
     * The same predicate for another active membership of the active
     * organization (e.g. before assigning work to it).
     */
    public function canMembershipView(string $membershipId, string $projectId): bool;
}
