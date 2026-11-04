<?php

namespace Modules\Work\Tests\Security;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\IdentityTenancy\Application\CreateOrganization;
use Modules\IdentityTenancy\Infrastructure\Persistence\User;
use Modules\Platform\Contracts\Tenancy\OrganizationId;
use Modules\Platform\Contracts\Tenancy\TenantContext;
use Modules\Platform\Tests\Security\SecurityTestCase;
use Modules\Portfolio\Application\CreateProject;

/**
 * 03 increment 2: Work core schema as the runtime role (W1-W4, W9, W10, W13).
 */
final class WorkSchemaTest extends SecurityTestCase
{
    private TenantContext $tenant;

    private User $owner;

    private OrganizationId $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->app->make(TenantContext::class);
        $this->owner = User::factory()->create(['email' => Str::uuid().'@example.test']);
        $this->organization = $this->app->make(CreateOrganization::class)->handle($this->owner, 'Work Schema');
    }

    public function test_items_keys_and_immutability(): void
    {
        $this->inOrg(function () {
            $project = $this->project('ORBX');
            $item = $this->item($project, 'task');

            $row = DB::selectOne('select key, number, status, priority, lock_version from work.items where id = ?', [$item]);
            $this->assertSame(['ORBX-1', 1, 'backlog', 'none', 0], [$row->key, $row->number, $row->status, $row->priority, (int) $row->lock_version]);

            $this->assertSqlState('23505', fn (Connection $db) => $this->insertItem($db, $project, 'task', 1, 'ORBX-1'));
            $this->assertSqlState('23514', fn (Connection $db) => $this->insertItem($db, $project, 'story', 99, 'ORBX-99'));
            $this->assertSqlState('23514', fn (Connection $db) => $db->update("update work.items set status = 'done' where id = ?", [$item]));
            foreach (['key', 'number', 'project_id', 'created_by_membership_id'] as $column) {
                $this->assertSqlState('42501', fn (Connection $db) => $db->update("update work.items set {$column} = {$column} where id = ?", [$item]));
            }
            foreach (['items', 'item_events', 'labels', 'item_labels', 'project_counters'] as $table) {
                $this->assertSqlState('42501', fn (Connection $db) => $db->delete("delete from work.{$table}"));
            }
        });
    }

    public function test_hierarchy_rules(): void
    {
        $this->inOrg(function () {
            $project = $this->project('HIER');
            $other = $this->project('OTHR');
            $epic = $this->item($project, 'epic');
            $feature = $this->item($project, 'feature', $epic);
            $task = $this->item($project, 'task', $feature);
            $subtask = $this->item($project, 'task', $task);
            $foreignParent = $this->item($other, 'epic');

            // Same project only (the parent foreign key includes project_id).
            $this->assertSqlState('23503', fn (Connection $db) => $this->insertItem($db, $project, 'task', 90, 'HIER-90', $foreignParent));
            // Incompatible types: an epic under a task, a feature under a bug.
            $this->assertSqlState('23514', fn (Connection $db) => $this->insertItem($db, $project, 'epic', 91, 'HIER-91', $task));
            $bug = $this->item($project, 'bug');
            $this->assertSqlState('23514', fn (Connection $db) => $this->insertItem($db, $project, 'task', 92, 'HIER-92', $bug));
            // Cycle and own parent.
            $this->assertSqlState('23514', fn (Connection $db) => $db->update('update work.items set parent_id = ? where id = ?', [$subtask, $task]));
            $this->assertSqlState('23514', fn (Connection $db) => $db->update('update work.items set parent_id = id where id = ?', [$task]));
            // Changing a type must keep its children compatible.
            $this->assertSqlState('23514', fn (Connection $db) => $db->update("update work.items set type = 'bug' where id = ?", [$task]));
            // Depth 5: epic > feature > task > subtask > sub-subtask, a sixth level fails.
            $level5 = $this->item($project, 'task', $subtask);
            $this->assertSqlState('23514', fn (Connection $db) => $this->insertItem($db, $project, 'task', 93, 'HIER-93', $level5));
        });
    }

    public function test_archived_project_rejects_items_and_labels_stay_in_their_project(): void
    {
        $this->inOrg(function () {
            $project = $this->project('LBL');
            $other = $this->project('LBO');
            $item = $this->item($project, 'task');
            $label = $this->label($project, 'Backend');
            $foreignLabel = $this->label($other, 'Backend');

            $this->assertSqlState('23505', fn (Connection $db) => $this->label($project, 'backend', $db));
            $this->assertSqlState('23503', fn (Connection $db) => $this->addLabel($db, $project, $item, $foreignLabel));
            $this->addLabel(DB::connection(), $project, $item, $label);
            $this->assertSqlState('23505', fn (Connection $db) => $this->addLabel($db, $project, $item, $label));

            DB::update("update portfolio.projects set status = 'archived' where id = ?", [$project]);
            $this->assertSqlState('23514', fn (Connection $db) => $this->insertItem($db, $project, 'task', 50, 'LBL-50'));
        });
    }

    public function test_activity_is_append_only_and_tenant_isolated(): void
    {
        $item = $this->inOrg(function () {
            $item = $this->item($this->project('ACT'), 'task');
            DB::insert(
                "insert into work.item_events (organization_id, item_id, type, actor_membership_id, changes)
                 values (platform.current_organization_id(), ?, 'created', ?, '{}')",
                [$item, $this->ownerMembership()]
            );
            $this->assertSqlState('42501', fn (Connection $db) => $db->update("update work.item_events set changes = '{}'"));
            $this->assertSqlState('23514', fn (Connection $db) => $db->insert(
                "insert into work.item_events (organization_id, item_id, type, actor_membership_id, changes)
                 values (platform.current_organization_id(), ?, 'x', ?, '[]')",
                [$item, $this->ownerMembership()]
            ));

            return $item;
        });

        $stranger = User::factory()->create(['email' => Str::uuid().'@example.test']);
        $other = $this->app->make(CreateOrganization::class)->handle($stranger, 'Other');
        $this->tenant->runFor($stranger, $other, function () use ($item) {
            $this->assertNull(DB::selectOne('select id from work.items where id = ?', [$item]));
            $this->assertSame(0, (int) DB::selectOne('select count(*) as total from work.item_events')->total);
        });
    }

    public function test_counter_allocation_serializes_per_project(): void
    {
        $project = $this->inOrg(fn () => $this->project('CNT'));
        config(['database.connections.pgsql_second' => config('database.connections.pgsql')]);
        $first = DB::connection('pgsql');
        $second = DB::connection('pgsql_second');

        $first->beginTransaction();
        $this->enter($first);
        $this->assertSame(1, $this->allocate($first, $project));

        $second->beginTransaction();
        $this->enter($second);
        $second->statement("set local lock_timeout = '300ms'");
        try {
            $this->allocate($second, $project);
            $this->fail('The second allocation must wait for the first transaction.');
        } catch (QueryException $blocked) {
            $this->assertSame('55P03', $blocked->errorInfo[0]);
        } finally {
            $second->rollBack();
        }
        $first->commit();

        $second->beginTransaction();
        $this->enter($second);
        $this->assertSame(2, $this->allocate($second, $project));
        $second->rollBack();
    }

    public function test_assignments_and_team_links(): void
    {
        $this->inOrg(function () {
            $project = $this->project('ASG');
            $item = $this->item($project, 'task');
            $owner = $this->ownerMembership();
            $assign = fn (Connection $db, string $membership, string $role) => $db->insert(
                'insert into work.item_assignments (organization_id, item_id, membership_id, role, assigned_by_membership_id)
                 values (platform.current_organization_id(), ?, ?, ?, ?)',
                [$item, $membership, $role, $owner]
            );

            $assign(DB::connection(), $owner, 'owner');
            $this->assertSqlState('23505', fn (Connection $db) => $assign($db, $owner, 'collaborator'));
            $this->assertSqlState('23503', fn (Connection $db) => $assign($db, (string) Str::uuid(), 'collaborator'));
            $this->assertSqlState('42501', fn (Connection $db) => $db->delete('delete from work.item_assignments'));
            $this->assertSqlState('42501', fn (Connection $db) => $db->update('update work.item_assignments set membership_id = membership_id'));

            $team = (string) DB::selectOne(
                "insert into teams.teams (organization_id, name, created_by_membership_id) values (platform.current_organization_id(), 'T', ?) returning id::text as id",
                [$owner]
            )->id;
            $archived = (string) DB::selectOne(
                "insert into teams.teams (organization_id, name, created_by_membership_id) values (platform.current_organization_id(), 'Old', ?) returning id::text as id",
                [$owner]
            )->id;
            DB::update("update teams.teams set status = 'archived', archived_at = now() where id = ?", [$archived]);
            $link = fn (Connection $db, string $teamId) => $db->insert(
                'insert into work.item_team_links (organization_id, item_id, team_id, linked_by_membership_id)
                 values (platform.current_organization_id(), ?, ?, ?)',
                [$item, $teamId, $owner]
            );
            $link(DB::connection(), $team);
            $this->assertSqlState('23505', fn (Connection $db) => $link($db, $team));
            $this->assertSqlState('23514', fn (Connection $db) => $link($db, $archived));
            $this->assertSqlState('23514', fn (Connection $db) => $link($db, (string) Str::uuid()));
        });
    }

    public function test_dependencies_reject_cycles_and_other_projects(): void
    {
        $this->inOrg(function () {
            $project = $this->project('DEP');
            $other = $this->project('DPO');
            [$a, $b, $c] = [$this->item($project, 'task'), $this->item($project, 'task'), $this->item($project, 'task')];
            $foreign = $this->item($other, 'task');

            $this->depend(DB::connection(), $project, $a, $b);
            $this->depend(DB::connection(), $project, $b, $c);
            $this->assertSqlState('23514', fn (Connection $db) => $this->depend($db, $project, $b, $a));
            $this->assertSqlState('23514', fn (Connection $db) => $this->depend($db, $project, $c, $a));
            $this->assertSqlState('23514', fn (Connection $db) => $this->depend($db, $project, $a, $a));
            $this->assertSqlState('23505', fn (Connection $db) => $this->depend($db, $project, $a, $b));
            $this->assertSqlState('23503', fn (Connection $db) => $this->depend($db, $project, $a, $foreign));
            $this->assertSqlState('23503', fn (Connection $db) => $this->depend($db, $other, $a, $foreign));
        });
    }

    public function test_concurrent_dependencies_wait_for_each_other(): void
    {
        [$project, $a, $b] = $this->inOrg(function () {
            $project = $this->project('DCC');

            return [$project, $this->item($project, 'task'), $this->item($project, 'task')];
        });
        config(['database.connections.pgsql_second' => config('database.connections.pgsql')]);
        $first = DB::connection('pgsql');
        $second = DB::connection('pgsql_second');

        $first->beginTransaction();
        $this->enter($first);
        $this->depend($first, $project, $a, $b);

        $second->beginTransaction();
        $this->enter($second);
        $second->statement("set local lock_timeout = '300ms'");
        try {
            $this->depend($second, $project, $b, $a);
            $this->fail('The opposite dependency must wait for the first transaction.');
        } catch (QueryException $blocked) {
            $this->assertSame('55P03', $blocked->errorInfo[0]);
        } finally {
            $second->rollBack();
        }
        $first->commit();

        $this->inOrg(fn () => $this->assertSqlState('23514', fn (Connection $db) => $this->depend($db, $project, $b, $a)));
    }

    private function depend(Connection $db, string $project, string $blocker, string $blocked): void
    {
        $db->insert(
            'insert into work.item_dependencies (organization_id, project_id, blocker_item_id, blocked_item_id, created_by_membership_id)
             values (platform.current_organization_id(), ?, ?, ?, ?)',
            [$project, $blocker, $blocked, $this->ownerMembership()]
        );
    }

    private function allocate(Connection $db, string $project): int
    {
        return (int) $db->selectOne(
            'insert into work.project_counters (organization_id, project_id, next_number)
             values (platform.current_organization_id(), ?, 2)
             on conflict (organization_id, project_id) do update set next_number = work.project_counters.next_number + 1
             returning next_number - 1 as number',
            [$project]
        )->number;
    }

    private function project(string $key): string
    {
        return $this->app->make(CreateProject::class)->handle($this->owner, $key, "Project {$key}");
    }

    private function item(string $project, string $type, ?string $parent = null): string
    {
        $number = $this->allocate(DB::connection(), $project);
        $key = (string) DB::selectOne('select key from portfolio.projects where id = ?', [$project])->key;

        return $this->insertItem(DB::connection(), $project, $type, $number, "{$key}-{$number}", $parent);
    }

    private function insertItem(Connection $db, string $project, string $type, int $number, string $key, ?string $parent = null): string
    {
        return (string) $db->selectOne(
            'insert into work.items (organization_id, project_id, number, key, type, parent_id, title, created_by_membership_id)
             values (platform.current_organization_id(), ?, ?, ?, ?, ?, ?, ?) returning id::text as id',
            [$project, $number, $key, $type, $parent, "Item {$key}", $this->ownerMembership()]
        )->id;
    }

    private function label(string $project, string $name, ?Connection $db = null): string
    {
        return (string) ($db ?? DB::connection())->selectOne(
            'insert into work.labels (organization_id, project_id, name, created_by_membership_id)
             values (platform.current_organization_id(), ?, ?, ?) returning id::text as id',
            [$project, $name, $this->ownerMembership()]
        )->id;
    }

    private function addLabel(Connection $db, string $project, string $item, string $label): void
    {
        $db->insert(
            'insert into work.item_labels (organization_id, project_id, item_id, label_id, added_by_membership_id)
             values (platform.current_organization_id(), ?, ?, ?, ?)',
            [$project, $item, $label, $this->ownerMembership()]
        );
    }

    private function ownerMembership(): string
    {
        return (string) DB::selectOne('select id::text as id from identity.organization_memberships where user_id = ?', [$this->owner->getKey()])->id;
    }

    private function enter(Connection $db): void
    {
        $db->selectOne("select set_config('app.organization_id', ?, true)", [$this->organization->value]);
    }

    private function inOrg(\Closure $callback): mixed
    {
        return $this->tenant->runFor($this->owner, $this->organization, $callback);
    }
}
