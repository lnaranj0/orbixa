<?php

namespace Modules\Portfolio\Infrastructure;

use Modules\Access\Contracts\Scope;
use Modules\Access\Contracts\ScopedRoleProvider;
use Modules\Access\Contracts\ScopedRoles;

/** "Proyecto propio": an active manager of the project holds project.manager over it. */
final class PortfolioScopedRoleProvider implements ScopedRoleProvider
{
    public function __construct(private readonly ProjectStore $projects) {}

    public function scopedRolesOf(string $membershipId, Scope $scope): array
    {
        return $scope->type === Scope::PROJECT && $this->projects->isActiveMember($scope->id, $membershipId, 'manager')
            ? [ScopedRoles::PROJECT_MANAGER]
            : [];
    }
}
