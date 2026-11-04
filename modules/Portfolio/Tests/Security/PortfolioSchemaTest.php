<?php

namespace Modules\Portfolio\Tests\Security;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\IdentityTenancy\Application\CreateOrganization;
use Modules\IdentityTenancy\Infrastructure\Persistence\User;
use Modules\Platform\Contracts\Tenancy\OrganizationId;
use Modules\Platform\Contracts\Tenancy\TenantContext;
use Modules\Platform\Tests\Security\SecurityTestCase;
use Modules\Teams\Application\CreateTeam;

/**
 * 02 increment 5: database invariants of the Portfolio schema, exercised
 * as the real runtime role (use cases arrive in increment 6).
 */
final class PortfolioSchemaTest extends SecurityTestCase
{
    private TenantContext $tenant;

    private User $owner;

    private OrganizationId $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->app->make(TenantContext::class);
        $this->owner = User::factory()->create(['email' => Str::uuid().'@example.test']);
        $this->organization = $this->app->make(CreateOrganization::class)->handle($this->owner, 'Portfolio Schema');
    }

    public function test_key_format_uniqueness_and_immutability(): void
    {
        $this->inOrg(function () {
            $project = $this->project('ORBX');

            $this->assertSqlState('23505', fn (Connection $db) => $this->insertProject($db, 'ORBX'));
            $this->assertSqlState('23514', fn (Connection $db) => $this->insertProject($db, 'orbx'));
            $this->assertSqlState('23514', fn (Connection $db) => $this->insertProject($db, 'TOOLONGKEY1'));
            $this->assertSqlState('42501', fn (Connection $db) => $db->update("update portfolio.projects set key = 'NEWK' where id = ?", [$project]));
            $this->assertSqlState('42501', fn (Connection $db) => $db->update('update portfolio.projects set created_by_membership_id = null where id = ?', [$project]));
            $this->assertSqlState('23514', fn (Connection $db) => $db->update("update portfolio.projects set confidentiality = 'secret' where id = ?", [$project]));
        });
    }

    public function test_concurrent_duplicate_key_has_a_single_winner(): void
    {
        config(['database.connections.pgsql_second' => config('database.connections.pgsql')]);
        $first = DB::connection('pgsql');
        $second = DB::connection('pgsql_second');
        $key = 'K'.strtoupper(Str::random(5));

        $first->beginTransaction();
        $this->enter($first);
        $this->insertProject($first, $key);

        $second->beginTransaction();
        $this->enter($second);
        $second->statement("set local lock_timeout = '300ms'");
        try {
            $this->insertProject($second, $key);
            $this->fail('The duplicate insert must wait for the first transaction.');
        } catch (QueryException $blocked) {
            $this->assertSame('55P03', $blocked->errorInfo[0]);
        } finally {
            $second->rollBack();
        }

        $first->commit();

        $second->beginTransaction();
        $this->enter($second);
        try {
            $this->insertProject($second, $key);
            $this->fail('Expected unique violation.');
        } catch (QueryException $duplicate) {
            $this->assertSame('23505', $duplicate->errorInfo[0]);
        } finally {
            $second->rollBack();
        }
    }

    public function test_members_links_history_and_archived_projects(): void
    {
        [$foreignMembership, $foreignTeam] = $this->foreignMembershipAndTeam();

        $this->inOrg(function () use ($foreignMembership, $foreignTeam) {
            $project = $this->project('MEMB');
            $owner = $this->ownerMembership();
            $team = $this->app->make(CreateTeam::class)->handle($this->owner, 'Linked team');

            $addMember = fn (Connection $db, string $membership) => $db->insert(
                'insert into portfolio.project_members (organization_id, project_id, membership_id, assigned_by_membership_id)
                 values (platform.current_organization_id(), ?, ?, ?)',
                [$project, $membership, $owner]
            );
            $link = fn (Connection $db, string $teamId) => $db->insert(
                'insert into portfolio.project_team_links (organization_id, project_id, team_id, linked_by_membership_id)
                 values (platform.current_organization_id(), ?, ?, ?)',
                [$project, $teamId, $owner]
            );

            $addMember(DB::connection(), $owner);
            $this->assertSqlState('23505', fn (Connection $db) => $addMember($db, $owner));
            $this->assertSqlState('23503', fn (Connection $db) => $addMember($db, $foreignMembership));

            $link(DB::connection(), $team);
            $this->assertSqlState('23505', fn (Connection $db) => $link($db, $team));
            // The active-team trigger (RLS: a foreign team is invisible) fires before the foreign key.
            $this->assertSqlState('23514', fn (Connection $db) => $link($db, $foreignTeam));

            foreach (['projects', 'project_members', 'project_team_links'] as $table) {
                $this->assertSqlState('42501', fn (Connection $db) => $db->delete("delete from portfolio.{$table}"));
            }
            $this->assertSqlState('42501', fn (Connection $db) => $db->update('update portfolio.project_members set membership_id = gen_random_uuid()'));
            $this->assertSqlState('42501', fn (Connection $db) => $db->update('update portfolio.project_team_links set team_id = gen_random_uuid()'));

            DB::update("update portfolio.projects set status = 'archived' where id = ?", [$project]);
            $other = $this->app->make(CreateTeam::class)->handle($this->owner, 'Late team');
            $this->assertSqlState('23514', fn (Connection $db) => $link($db, $other));
        });
    }

    public function test_projects_are_isolated_between_organizations(): void
    {
        $mine = $this->inOrg(fn () => $this->project('MINE'));
        $otherOwner = User::factory()->create(['email' => Str::uuid().'@example.test']);
        $other = $this->app->make(CreateOrganization::class)->handle($otherOwner, 'Other');

        $this->tenant->runFor($otherOwner, $other, function () use ($mine) {
            $this->assertSame(0, (int) DB::selectOne('select count(*) as total from portfolio.projects where id = ?', [$mine])->total);
            // Same key in another organization is allowed: uniqueness is per organization.
            $this->assertIsString($this->project('MINE'));
        });
    }

    private function project(string $key): string
    {
        return $this->insertProject(DB::connection(), $key);
    }

    private function insertProject(Connection $db, string $key): string
    {
        return (string) $db->selectOne(
            'insert into portfolio.projects (organization_id, key, name, created_by_membership_id)
             values (platform.current_organization_id(), ?, ?, (select id from identity.organization_memberships limit 1))
             returning id::text as id',
            [$key, 'Project '.$key]
        )->id;
    }

    private function ownerMembership(): string
    {
        return (string) DB::selectOne('select id::text as id from identity.organization_memberships where user_id = ?', [$this->owner->getKey()])->id;
    }

    /**
     * @return array{string, string}
     */
    private function foreignMembershipAndTeam(): array
    {
        $otherOwner = User::factory()->create(['email' => Str::uuid().'@example.test']);
        $other = $this->app->make(CreateOrganization::class)->handle($otherOwner, 'Foreign');

        return $this->tenant->runFor($otherOwner, $other, fn () => [
            (string) DB::selectOne('select id::text as id from identity.organization_memberships')->id,
            $this->app->make(CreateTeam::class)->handle($otherOwner, 'Foreign team'),
        ]);
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
