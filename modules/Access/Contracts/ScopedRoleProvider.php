<?php

namespace Modules\Access\Contracts;

/**
 * Implemented by the module that owns a scoped role (Teams: team.lead,
 * Portfolio: project.manager). It reports which scoped role keys the
 * membership holds over the scope; Access alone maps them to permissions
 * (ScopedRoleTemplates), so the permission matrix stays in one place.
 */
interface ScopedRoleProvider
{
    public const TAG = 'orbixa.scoped-role-providers';

    /**
     * @return list<string> scoped role keys, e.g. ['team.lead']
     */
    public function scopedRolesOf(string $membershipId, Scope $scope): array;
}
