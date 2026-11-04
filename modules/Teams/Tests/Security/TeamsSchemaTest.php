<?php

namespace Modules\Teams\Tests\Security;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\IdentityTenancy\Application\CreateOrganization;
use Modules\IdentityTenancy\Infrastructure\Persistence\User;
use Modules\Platform\Contracts\Tenancy\OrganizationId;
use Modules\Platform\Contracts\Tenancy\TenantContext;
use Modules\Platform\Tests\Security\SecurityTestCase;

/**
 * 02 increment 2: database invariants of the Teams schema, exercised as
 * the real runtime role (the application layer arrives in increment 3).
 */
final class TeamsSchemaTest extends SecurityTestCase
{
    private TenantContext $tenant;

    private User $owner;

    private OrganizationId $organization;

    private string $ownerMembership;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->app->make(TenantContext::class);
        $this->owner = User::factory()->create(['email' => Str::uuid().'@example.test']);
        $this->organization = $this->app->make(CreateOrganization::class)->handle($this->owner, 'Teams Schema');
        $this->ownerMembership = $this->inOrg(fn () => (string) DB::selectOne('select id::text as id from identity.organization_memberships')->id);
    }

    public function test_direct_and_indirect_cycles_are_rejected(): void
    {
        $this->inOrg(function () {
            $a = $this->team('A');
            $b = $this->team('B', $a);
            $c = $this->team('C', $b);

            $this->assertSqlState('23514', fn (Connection $db) => $db->update('update teams.teams set parent_team_id = id where id = ?', [$a]));
            $this->assertSqlState('23514', fn (Connection $db) => $db->update('update teams.teams set parent_team_id = ? where id = ?', [$c, $a]));
            $this->assertSqlState('23514', fn (Connection $db) => $db->update('update teams.teams set parent_team_id = ? where id = ?', [$b, $a]));
        });
    }

    public function test_maximum_depth_is_five_including_moved_subtrees(): void
    {
        $this->inOrg(function () {
            $parent = null;
            for ($level = 1; $level <= 5; $level++) {
                $parent = $this->team("L{$level}", $parent);
            }
            $this->assertSqlState('23514', fn (Connection $db) => $this->insertTeam($db, 'L6', $parent));

            // A 2-level subtree cannot be moved under level 4.
            $level4 = (string) DB::selectOne("select id::text as id from teams.teams where name = 'L4'")->id;
            $root = $this->team('Root');
            $this->team('Child', $root);
            $this->assertSqlState('23514', fn (Connection $db) => $db->update('update teams.teams set parent_team_id = ? where id = ?', [$level4, $root]));
        });
    }

    public function test_archived_parents_and_archiving_with_children_are_rejected(): void
    {
        $this->inOrg(function () {
            $parent = $this->team('Parent');
            $this->team('Child', $parent);
            $this->assertSqlState('23514', fn (Connection $db) => $db->update(
                "update teams.teams set status = 'archived', archived_at = now() where id = ?", [$parent]
            ));

            $archived = $this->team('Archived');
            DB::update("update teams.teams set status = 'archived', archived_at = now() where id = ?", [$archived]);
            $this->assertSqlState('23514', fn (Connection $db) => $this->insertTeam($db, 'Orphan', $archived));
            $this->assertSqlState('23514', fn (Connection $db) => $db->insert(
                'insert into teams.team_members (organization_id, team_id, membership_id, assigned_by_membership_id)
                 values (platform.current_organization_id(), ?, ?, ?)',
                [$archived, $this->ownerMembership, $this->ownerMembership]
            ));
        });
    }

    public function test_concurrent_opposite_moves_cannot_both_succeed(): void
    {
        [$a, $b] = $this->inOrg(fn () => [$this->team('A'), $this->team('B')]);
        config(['database.connections.pgsql_second' => config('database.connections.pgsql')]);
        $first = DB::connection('pgsql');
        $second = DB::connection('pgsql_second');

        $first->beginTransaction();
        $this->enter($first);
        $first->update('update teams.teams set parent_team_id = ? where id = ?', [$b, $a]); // holds the hierarchy lock

        $second->beginTransaction();
        $this->enter($second);
        $second->statement("set local lock_timeout = '300ms'");
        try {
            $second->update('update teams.teams set parent_team_id = ? where id = ?', [$a, $b]);
            $this->fail('The opposite move must wait for the hierarchy lock.');
        } catch (QueryException $blocked) {
            $this->assertSame('55P03', $blocked->errorInfo[0], 'Concurrent hierarchy changes are serialized.');
        } finally {
            $second->rollBack();
        }

        $first->commit();

        // After the first move commits, the opposite move is a cycle.
        $second->beginTransaction();
        $this->enter($second);
        try {
            $second->update('update teams.teams set parent_team_id = ? where id = ?', [$a, $b]);
            $this->fail('Expected cycle rejection.');
        } catch (QueryException $cycle) {
            $this->assertSame('23514', $cycle->errorInfo[0]);
        } finally {
            $second->rollBack();
        }
    }

    public function test_team_members_rules(): void
    {
        $foreignMembership = $this->foreignMembership();

        $this->inOrg(function () use ($foreignMembership) {
            $team = $this->team('Members');
            $add = fn (Connection $db, string $membership) => $db->insert(
                'insert into teams.team_members (organization_id, team_id, membership_id, assigned_by_membership_id)
                 values (platform.current_organization_id(), ?, ?, ?)',
                [$team, $membership, $this->ownerMembership]
            );

            $add(DB::connection(), $this->ownerMembership);
            $this->assertSqlState('23505', fn (Connection $db) => $add($db, $this->ownerMembership));
            $this->assertSqlState('23503', fn (Connection $db) => $add($db, $foreignMembership));
            $this->assertSqlState('42501', fn (Connection $db) => $db->update('update teams.team_members set membership_id = gen_random_uuid()'));
            $this->assertSqlState('42501', fn (Connection $db) => $db->delete('delete from teams.team_members'));
        });
    }

    public function test_runtime_cannot_delete_or_rewrite_history_and_teams_are_isolated(): void
    {
        $team = $this->inOrg(fn () => $this->team('Mine'));
        $foreignTeam = $this->inOrg(fn () => $this->team('Theirs'), $other = $this->otherOrganization());

        $this->inOrg(function () use ($team, $foreignTeam) {
            $this->assertSame(0, (int) DB::selectOne('select count(*) as total from teams.teams where id = ?', [$foreignTeam])->total);
            // A foreign parent is invisible under RLS, so it is rejected exactly like a nonexistent one.
            $foreignError = $this->hierarchyError(fn () => $this->insertTeam(DB::connection(), 'Cross', $foreignTeam));
            $unknownError = $this->hierarchyError(fn () => $this->insertTeam(DB::connection(), 'Ghost', (string) Str::uuid()));
            $this->assertSame(['23514', 'team hierarchy: parent must be an active team'], $foreignError);
            $this->assertSame($unknownError, $foreignError);
            $this->assertSqlState('42501', fn (Connection $db) => $db->delete('delete from teams.teams'));
            DB::insert(
                'insert into teams.team_parent_changes (organization_id, team_id, changed_by_membership_id)
                 values (platform.current_organization_id(), ?, ?)',
                [$team, $this->ownerMembership]
            );
            $this->assertSqlState('42501', fn (Connection $db) => $db->update('update teams.team_parent_changes set changed_at = now()'));
            $this->assertSqlState('42501', fn (Connection $db) => $db->delete('delete from teams.team_parent_changes'));
        });
        $this->assertNotNull($other);
    }

    /**
     * @return array{string, string}
     */
    private function hierarchyError(\Closure $operation): array
    {
        DB::beginTransaction();
        try {
            $operation();
            $this->fail('Expected a hierarchy rejection.');
        } catch (QueryException $exception) {
            preg_match('/ERROR:\s+([^\n(]+?)\s*(?:\n|CONTEXT|\(Connection)/', $exception->getMessage(), $match);

            return [(string) $exception->errorInfo[0], trim($match[1] ?? '')];
        } finally {
            DB::rollBack();
        }
    }

    private function team(string $name, ?string $parent = null): string
    {
        return $this->insertTeam(DB::connection(), $name, $parent);
    }

    private function insertTeam(Connection $db, string $name, ?string $parent = null): string
    {
        return (string) $db->selectOne(
            'insert into teams.teams (organization_id, name, parent_team_id, created_by_membership_id)
             values (platform.current_organization_id(), ?, ?::uuid, ?::uuid) returning id::text as id',
            [$name, $parent, $this->ownerMembershipFor($db)]
        )->id;
    }

    private function ownerMembershipFor(Connection $db): string
    {
        return (string) $db->selectOne('select id::text as id from identity.organization_memberships limit 1')->id;
    }

    private function enter(Connection $db): void
    {
        $db->selectOne("select set_config('app.organization_id', ?, true)", [$this->organization->value]);
    }

    private ?User $otherOwner = null;

    private function otherOrganization(): OrganizationId
    {
        $this->otherOwner = User::factory()->create(['email' => Str::uuid().'@example.test']);

        return $this->app->make(CreateOrganization::class)->handle($this->otherOwner, 'Other');
    }

    private function foreignMembership(): string
    {
        $other = $this->otherOrganization();

        return $this->tenant->runFor($this->otherOwner, $other, fn () => (string) DB::selectOne('select id::text as id from identity.organization_memberships')->id);
    }

    private function inOrg(\Closure $callback, ?OrganizationId $organization = null): mixed
    {
        $actor = $organization === null ? $this->owner : $this->otherOwner;

        return $this->tenant->runFor($actor, $organization ?? $this->organization, $callback);
    }
}
