<?php

namespace Modules\Access\Domain;

use Modules\Access\Contracts\Permission;

/**
 * Base roles copied into every new organization. Only the "Sí" cells of
 * docs/PERMISSIONS.md are granted: "Config.", "Equipo propio", "Según
 * proyecto" and "Scope" stay ungranted until their modules define the
 * scope. The viewer has no domain permission (monitoring grants: 04).
 */
final class RoleTemplates
{
    public const OWNER = 'org_owner';

    public const VERSION = 1;

    /**
     * @return array<string, array{name: string, permissions: list<Permission>}>
     */
    public static function all(): array
    {
        $admin = [
            Permission::OrganizationManage, Permission::MembersInvite, Permission::MembersManage,
            Permission::TeamsManage, Permission::ProjectsCreate, Permission::ProjectsManage,
            Permission::WorkCreate, Permission::WorkEdit, Permission::WorkTransition, Permission::WorkAssign,
            Permission::WorkApprove, Permission::PlanningManage, Permission::IntegrationsGithubManage,
            Permission::SpecsLink, Permission::AgentsManage, Permission::AgentsAssign, Permission::AuditView,
        ];

        return [
            self::OWNER => ['name' => 'Owner', 'permissions' => $admin],
            'org_admin' => ['name' => 'Administrador', 'permissions' => $admin],
            'project_manager' => ['name' => 'Project manager', 'permissions' => [
                Permission::WorkCreate, Permission::WorkEdit, Permission::WorkTransition, Permission::WorkAssign,
                Permission::WorkApprove, Permission::PlanningManage, Permission::SpecsLink, Permission::AgentsAssign,
            ]],
            'team_lead' => ['name' => 'Team lead', 'permissions' => [Permission::WorkCreate, Permission::SpecsLink]],
            'developer' => ['name' => 'Developer', 'permissions' => [Permission::WorkCreate, Permission::SpecsLink]],
            'reviewer' => ['name' => 'Reviewer', 'permissions' => []],
            'viewer' => ['name' => 'Viewer', 'permissions' => []],
        ];
    }
}
