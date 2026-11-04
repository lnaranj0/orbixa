<?php

namespace Modules\Access\Domain;

use Modules\Access\Contracts\Permission;
use Modules\Access\Contracts\ScopedRoles;

/**
 * Permissions granted by scoped roles ("Equipo propio" / "Proyecto propio"
 * cells of docs/PERMISSIONS.md), valid only within their scope.
 */
final class ScopedRoleTemplates
{
    /**
     * @return array<string, list<Permission>>
     */
    public static function all(): array
    {
        return [
            ScopedRoles::TEAM_LEAD => [Permission::TeamsManage],
            ScopedRoles::PROJECT_MANAGER => [Permission::ProjectsManage],
        ];
    }

    /**
     * @param  list<string>  $roles
     */
    public static function grants(array $roles, Permission $permission): bool
    {
        foreach ($roles as $role) {
            if (in_array($permission, self::all()[$role] ?? [], true)) {
                return true;
            }
        }

        return false;
    }
}
