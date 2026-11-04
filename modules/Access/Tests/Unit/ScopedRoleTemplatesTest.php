<?php

namespace Modules\Access\Tests\Unit;

use Modules\Access\Contracts\Permission;
use Modules\Access\Contracts\ScopedRoles;
use Modules\Access\Domain\ScopedRoleTemplates;
use PHPUnit\Framework\TestCase;

final class ScopedRoleTemplatesTest extends TestCase
{
    public function test_scoped_roles_grant_only_their_documented_permission(): void
    {
        $this->assertTrue(ScopedRoleTemplates::grants([ScopedRoles::TEAM_LEAD], Permission::TeamsManage));
        $this->assertFalse(ScopedRoleTemplates::grants([ScopedRoles::TEAM_LEAD], Permission::MembersManage));
        $this->assertFalse(ScopedRoleTemplates::grants([ScopedRoles::TEAM_LEAD], Permission::ProjectsManage));
        $this->assertTrue(ScopedRoleTemplates::grants([ScopedRoles::PROJECT_MANAGER], Permission::ProjectsManage));
        $this->assertFalse(ScopedRoleTemplates::grants([ScopedRoles::PROJECT_MANAGER], Permission::TeamsManage));
        $this->assertFalse(ScopedRoleTemplates::grants(['unknown.role'], Permission::TeamsManage));
        $this->assertFalse(ScopedRoleTemplates::grants([], Permission::TeamsManage));
    }
}
