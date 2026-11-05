<?php

namespace Modules\GitHubIntegration\Application;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Access\Contracts\Permission;
use Modules\GitHubIntegration\Contracts\GitHubOperationDenied;
use Modules\IdentityTenancy\Contracts\CurrentMembership;
use Modules\IdentityTenancy\Contracts\MembershipLookup;

/** integrations.github.manage over the organization, inside its tenant context. */
final class ManagePermission
{
    public function __construct(
        private readonly Gate $gate,
        private readonly CurrentMembership $memberships,
        private readonly MembershipLookup $lookup,
    ) {}

    /** @return string the actor's membership id, locked until commit */
    public function require(Authenticatable $actor): string
    {
        $membershipId = $this->memberships->idFor($actor);
        if ($membershipId === null || ! $this->lookup->lockActive($membershipId)) {
            throw GitHubOperationDenied::because('No active membership.');
        }
        if (! $this->gate->forUser($actor)->allows(Permission::IntegrationsGithubManage->value)) {
            throw GitHubOperationDenied::because('integrations.github.manage is required.');
        }

        return $membershipId;
    }
}
