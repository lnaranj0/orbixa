<?php

namespace Modules\Access\Tests\Unit;

use Modules\Access\Contracts\Permission;
use Modules\Access\Domain\RoleTemplates;
use PHPUnit\Framework\TestCase;

final class RoleTemplatesTest extends TestCase
{
    public function test_base_roles_match_the_documented_matrix(): void
    {
        $templates = RoleTemplates::all();

        $this->assertSame(
            ['org_owner', 'org_admin', 'project_manager', 'team_lead', 'developer', 'reviewer', 'viewer'],
            array_keys($templates)
        );
        $this->assertSame([], $templates['viewer']['permissions'], 'Viewer has zero domain permissions (monitoring: 04).');
        $this->assertSame([], $templates['reviewer']['permissions'], 'Reviewer permissions are scoped (Según política).');
        $this->assertEqualsCanonicalizing($templates['org_owner']['permissions'], $templates['org_admin']['permissions']);
    }

    public function test_nobody_can_execute_agents_in_this_phase(): void
    {
        foreach (RoleTemplates::all() as $key => $template) {
            $this->assertNotContains(Permission::AgentsExecute, $template['permissions'], "{$key} must not hold agents.execute");
        }
    }

    public function test_permissions_are_not_duplicated(): void
    {
        foreach (RoleTemplates::all() as $key => $template) {
            $values = array_map(fn (Permission $p) => $p->value, $template['permissions']);
            $this->assertSame(array_values(array_unique($values)), $values, "{$key} has duplicated permissions");
        }
    }
}
