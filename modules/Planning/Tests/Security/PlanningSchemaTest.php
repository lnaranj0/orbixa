<?php

namespace Modules\Planning\Tests\Security;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\IdentityTenancy\Application\CreateOrganization;
use Modules\IdentityTenancy\Infrastructure\Persistence\User;
use Modules\Platform\Contracts\Tenancy\OrganizationId;
use Modules\Platform\Contracts\Tenancy\TenantContext;
use Modules\Platform\Tests\Security\SecurityTestCase;
use Modules\Portfolio\Application\CreateProject;
use Modules\Work\Application\CreateWorkItem;

/**
 * 05 increment 9: Planning schema as the runtime role (D-B, D-D, P9).
 */
final class PlanningSchemaTest extends SecurityTestCase
{
    private TenantContext $tenant;

    private User $owner;

    private OrganizationId $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->app->make(TenantContext::class);
        $this->owner = User::factory()->create(['email' => Str::uuid().'@example.test']);
        $this->organization = $this->app->make(CreateOrganization::class)->handle($this->owner, 'Planning Schema');
    }

    public function test_cycle_and_membership_invariants(): void
    {
        $this->inOrg(function () {
            $project = $this->project('PLN');
            $other = $this->project('PLO');
            $item = $this->item($project, 'In cycle');
            $foreignItem = $this->item($other, 'Other project');

            $cycle = $this->cycle(DB::connection(), $project, 'Sprint 1', 'active');
            $this->assertSqlState('23505', fn (Connection $db) => $this->cycle($db, $project, 'Sprint 2', 'active'));
            $this->assertSqlState('23514', fn (Connection $db) => $db->insert(
                "insert into planning.cycles (organization_id, project_id, name, starts_on, ends_on, created_by_membership_id)
                 values (platform.current_organization_id(), ?, 'Backwards', current_date, current_date - 1, ?)",
                [$project, $this->ownerMembership()]
            ));
            $this->assertSqlState('23514', fn (Connection $db) => $db->insert(
                "insert into planning.cycles (organization_id, project_id, name, starts_on, ends_on, created_by_membership_id)
                 values (platform.current_organization_id(), ?, 'Too long', current_date, current_date + 200, ?)",
                [$project, $this->ownerMembership()]
            ));

            $this->addItem(DB::connection(), $project, $cycle, $item);
            // One open cycle per item; item and cycle in the same project.
            $next = $this->cycle(DB::connection(), $project, 'Sprint 2', 'planned');
            $this->assertSqlState('23505', fn (Connection $db) => $this->addItem($db, $project, $next, $item));
            $this->assertSqlState('23503', fn (Connection $db) => $this->addItem($db, $project, $cycle, $foreignItem));
            $this->assertSqlState('23503', fn (Connection $db) => $this->addItem($db, $other, $cycle, $foreignItem));
            // Outcome fields go together.
            $this->assertSqlState('23514', fn (Connection $db) => $db->update("update planning.cycle_items set outcome = 'delivered' where item_id = ?", [$item]));

            // Closed cycles accept no new items.
            DB::update("update planning.cycles set status = 'closed', closed_at = now() where id = ?", [$next]);
            $another = $this->item($project, 'Late');
            $this->assertSqlState('23514', fn (Connection $db) => $this->addItem($db, $project, $next, $another));

            foreach (['project_settings', 'cycles', 'cycle_items'] as $table) {
                $this->assertSqlState('42501', fn (Connection $db) => $db->delete("delete from planning.{$table}"));
            }
            $this->assertSqlState('42501', fn (Connection $db) => $db->update('update planning.cycle_items set item_id = item_id'));
            $this->assertSqlState('42501', fn (Connection $db) => $db->update('update planning.cycles set project_id = project_id'));
            $this->assertSqlState('23514', fn (Connection $db) => $db->insert(
                "insert into planning.project_settings (organization_id, project_id, mode) values (platform.current_organization_id(), ?, 'waterfall')",
                [$project]
            ));
        });

        $stranger = User::factory()->create(['email' => Str::uuid().'@example.test']);
        $other = $this->app->make(CreateOrganization::class)->handle($stranger, 'Other');
        $this->tenant->runFor($stranger, $other, function () {
            $this->assertSame(0, (int) DB::selectOne('select count(*) as total from planning.cycles')->total);
            $this->assertSame(0, (int) DB::selectOne('select count(*) as total from planning.cycle_items')->total);
        });
    }

    private function project(string $key): string
    {
        return $this->app->make(CreateProject::class)->handle($this->owner, $key, "Project {$key}");
    }

    private function item(string $project, string $title): string
    {
        return $this->app->make(CreateWorkItem::class)->handle($this->owner, $project, ['type' => 'task', 'title' => $title]);
    }

    private function cycle(Connection $db, string $project, string $name, string $status): string
    {
        return (string) $db->selectOne(
            'insert into planning.cycles (organization_id, project_id, name, starts_on, ends_on, status, created_by_membership_id)
             values (platform.current_organization_id(), ?, ?, current_date, current_date + 14, ?, ?) returning id::text as id',
            [$project, $name, $status, $this->ownerMembership()]
        )->id;
    }

    private function addItem(Connection $db, string $project, string $cycle, string $item): void
    {
        $db->insert(
            'insert into planning.cycle_items (organization_id, project_id, cycle_id, item_id, added_by_membership_id)
             values (platform.current_organization_id(), ?, ?, ?, ?)',
            [$project, $cycle, $item, $this->ownerMembership()]
        );
    }

    private function ownerMembership(): string
    {
        return (string) DB::selectOne('select id::text as id from identity.organization_memberships where user_id = ?', [$this->owner->getKey()])->id;
    }

    private function inOrg(\Closure $callback): mixed
    {
        return $this->tenant->runFor($this->owner, $this->organization, $callback);
    }
}
