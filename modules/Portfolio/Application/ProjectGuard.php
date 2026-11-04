<?php

namespace Modules\Portfolio\Application;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Access\Contracts\Permission;
use Modules\Access\Contracts\Scope;
use Modules\IdentityTenancy\Contracts\CurrentMembership;
use Modules\Platform\Contracts\Tenancy\TenantContext;
use Modules\Platform\Contracts\Tenancy\TenantContextRequired;
use Modules\Portfolio\Contracts\ProjectNotFound;
use Modules\Portfolio\Contracts\ProjectOperationDenied;
use Modules\Portfolio\Contracts\ProjectVisibility;
use Modules\Portfolio\Infrastructure\ProjectStore;

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
    ) {}

    /** @return string actor membership id */
    public function context(Authenticatable $actor): string
    {
        $this->tenant->current() ?? throw TenantContextRequired::forOperation('Managing projects');

        return $this->memberships->idFor($actor) ?? throw ProjectOperationDenied::because('No active membership.');
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
}
