<?php

namespace Modules\Access\Contracts;

/**
 * Scoped role keys reported by ScopedRoleProvider implementations. Part of
 * the contract; their permissions are defined only inside Access.
 */
final class ScopedRoles
{
    public const TEAM_LEAD = 'team.lead';

    public const PROJECT_MANAGER = 'project.manager';
}
