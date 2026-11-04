<?php

namespace Modules\Access\Tests\Unit;

use Modules\Access\Contracts\Permission;
use Modules\Access\Domain\RoleAssignmentPolicy;
use Modules\Access\Domain\RoleTemplates;
use PHPUnit\Framework\TestCase;

final class RoleAssignmentPolicyTest extends TestCase
{
    public function test_nobody_changes_their_own_roles(): void
    {
        $this->assertNotNull(RoleAssignmentPolicy::denialReason(true, ['org_owner'], $this->admin(), 'viewer', []));
    }

    public function test_members_manage_is_required(): void
    {
        $this->assertNotNull(RoleAssignmentPolicy::denialReason(false, ['project_manager'], [Permission::WorkCreate], 'viewer', []));
    }

    public function test_only_an_owner_grants_owner(): void
    {
        $this->assertNotNull(RoleAssignmentPolicy::denialReason(false, ['org_admin'], $this->admin(), RoleTemplates::OWNER, $this->admin()));
        $this->assertNull(RoleAssignmentPolicy::denialReason(false, [RoleTemplates::OWNER], $this->admin(), RoleTemplates::OWNER, $this->admin()));
    }

    public function test_cannot_grant_permissions_the_actor_does_not_hold(): void
    {
        $actor = [Permission::MembersManage, Permission::WorkCreate];

        $this->assertNotNull(RoleAssignmentPolicy::denialReason(false, ['custom'], $actor, 'project_manager', [Permission::WorkApprove]));
        $this->assertNull(RoleAssignmentPolicy::denialReason(false, ['custom'], $actor, 'developer', [Permission::WorkCreate]));
    }

    /**
     * @return list<Permission>
     */
    private function admin(): array
    {
        return RoleTemplates::all()['org_admin']['permissions'];
    }
}
