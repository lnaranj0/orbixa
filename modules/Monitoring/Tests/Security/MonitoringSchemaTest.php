<?php

namespace Modules\Monitoring\Tests\Security;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\IdentityTenancy\Application\AcceptInvitation;
use Modules\IdentityTenancy\Application\CreateOrganization;
use Modules\IdentityTenancy\Application\InviteMember;
use Modules\IdentityTenancy\Infrastructure\Persistence\User;
use Modules\Platform\Contracts\Tenancy\OrganizationId;
use Modules\Platform\Contracts\Tenancy\TenantContext;
use Modules\Platform\Tests\Security\SecurityTestCase;
use Modules\Teams\Application\CreateTeam;

/**
 * 04 increment 3: Monitoring schema as the runtime role (M1, M5, M8, M9).
 */
final class MonitoringSchemaTest extends SecurityTestCase
{
    private TenantContext $tenant;

    private User $owner;

    private OrganizationId $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->app->make(TenantContext::class);
        $this->owner = $this->user();
        $this->organization = $this->app->make(CreateOrganization::class)->handle($this->owner, 'Monitoring Schema');
    }

    public function test_grant_shape_is_enforced(): void
    {
        $viewer = $this->member('viewer');
        $target = $this->member('developer');

        $this->inOrg(function () use ($viewer, $target) {
            $owner = $this->membershipId($this->owner);
            $viewerId = $this->membershipId($viewer);
            $targetId = $this->membershipId($target);
            $team = $this->app->make(CreateTeam::class)->handle($this->owner, 'Observed');

            $this->grant(DB::connection(), $viewerId, 'user', $targetId, null, $owner);
            $this->grant(DB::connection(), $viewerId, 'team', null, $team, $owner, includeDescendants: true);
            $this->grant(DB::connection(), $viewerId, 'organization', null, null, $owner);

            // Exactly one target per scope; descendants only for teams.
            $this->assertSqlState('23514', fn (Connection $db) => $this->grant($db, $viewerId, 'user', null, $team, $owner));
            $this->assertSqlState('23514', fn (Connection $db) => $this->grant($db, $viewerId, 'team', $targetId, null, $owner));
            $this->assertSqlState('23514', fn (Connection $db) => $this->grant($db, $viewerId, 'organization', $targetId, null, $owner));
            $this->assertSqlState('23514', fn (Connection $db) => $this->grant($db, $viewerId, 'user', $targetId, null, $owner, includeDescendants: true));
            $this->assertSqlState('23514', fn (Connection $db) => $this->grant($db, $viewerId, 'project', null, null, $owner));
            // Nobody grants themselves or observes themselves.
            $this->assertSqlState('23514', fn (Connection $db) => $this->grant($db, $owner, 'organization', null, null, $owner));
            $this->assertSqlState('23514', fn (Connection $db) => $this->grant($db, $viewerId, 'user', $viewerId, null, $owner));
            // Bounded validity.
            $this->assertSqlState('23514', fn (Connection $db) => $this->grant($db, $viewerId, 'organization', null, null, $owner, expires: '400 days'));
            $this->assertSqlState('23514', fn (Connection $db) => $this->grant($db, $viewerId, 'organization', null, null, $owner, expires: '-1 day'));
            // Unknown or foreign targets.
            $this->assertSqlState('23503', fn (Connection $db) => $this->grant($db, $viewerId, 'user', (string) Str::uuid(), null, $owner));
            $this->assertSqlState('23503', fn (Connection $db) => $this->grant($db, $viewerId, 'team', null, (string) Str::uuid(), $owner));
        });
    }

    public function test_runtime_cannot_rewrite_grants_or_history(): void
    {
        $viewer = $this->member('viewer');

        $this->inOrg(function () use ($viewer) {
            $owner = $this->membershipId($this->owner);
            $grant = $this->grant(DB::connection(), $this->membershipId($viewer), 'organization', null, null, $owner);
            DB::insert(
                "insert into monitoring.grant_events (organization_id, grant_id, type, actor_membership_id) values (platform.current_organization_id(), ?, 'created', ?)",
                [$grant, $owner]
            );

            foreach (['viewer_membership_id', 'scope_type', 'target_membership_id', 'target_team_id', 'granted_by_membership_id', 'starts_at', 'organization_id'] as $column) {
                $this->assertSqlState('42501', fn (Connection $db) => $db->update("update monitoring.grants set {$column} = {$column} where id = ?", [$grant]));
            }
            $this->assertSqlState('42501', fn (Connection $db) => $db->delete('delete from monitoring.grants'));
            $this->assertSqlState('42501', fn (Connection $db) => $db->update("update monitoring.grant_events set reason = 'x'"));
            $this->assertSqlState('42501', fn (Connection $db) => $db->delete('delete from monitoring.grant_events'));
            // A rejected attempt has no grant; any other event needs one.
            $this->assertSqlState('23514', fn (Connection $db) => $db->insert(
                "insert into monitoring.grant_events (organization_id, type, actor_membership_id) values (platform.current_organization_id(), 'created', ?)",
                [$owner]
            ));
            // Revocation fields go together.
            $this->assertSqlState('23514', fn (Connection $db) => $db->update('update monitoring.grants set revoked_at = now() where id = ?', [$grant]));
            DB::update("update monitoring.grants set revoked_at = now(), revoked_by_membership_id = ?, revoked_reason = 'revoked' where id = ?", [$owner, $grant]);
        });
    }

    public function test_grants_are_tenant_isolated(): void
    {
        $viewer = $this->member('viewer');
        $this->inOrg(fn () => $this->grant(DB::connection(), $this->membershipId($viewer), 'organization', null, null, $this->membershipId($this->owner)));

        $stranger = $this->user();
        $other = $this->app->make(CreateOrganization::class)->handle($stranger, 'Other');
        $this->tenant->runFor($stranger, $other, function () {
            $this->assertSame(0, (int) DB::selectOne('select count(*) as total from monitoring.grants')->total);
            $this->assertSame(0, (int) DB::selectOne('select count(*) as total from monitoring.grant_events')->total);
        });
    }

    private function grant(Connection $db, string $viewer, string $scope, ?string $targetMembership, ?string $targetTeam, string $grantedBy, bool $includeDescendants = false, string $expires = '30 days'): string
    {
        return (string) $db->selectOne(
            "insert into monitoring.grants (organization_id, viewer_membership_id, scope_type, target_membership_id, target_team_id, include_descendants, reason, granted_by_membership_id, expires_at)
             values (platform.current_organization_id(), ?, ?, ?, ?, ?, 'Follow-up', ?, now() + ?::interval) returning id::text as id",
            [$viewer, $scope, $targetMembership, $targetTeam, $includeDescendants, $grantedBy, $expires]
        )->id;
    }

    private function inOrg(\Closure $callback): mixed
    {
        return $this->tenant->runFor($this->owner, $this->organization, $callback);
    }

    private function user(): User
    {
        return User::factory()->create(['email' => Str::uuid().'@example.test']);
    }

    private function member(string $role): User
    {
        $user = $this->user();
        $ticket = $this->inOrg(fn () => $this->app->make(InviteMember::class)->handle($this->owner, $user->email, $role))->ticket;
        $this->app->make(AcceptInvitation::class)->handle($user, $this->organization, $ticket->reveal());

        return $user;
    }

    private function membershipId(User $user): string
    {
        return (string) DB::selectOne('select id::text as id from identity.organization_memberships where user_id = ?', [$user->getKey()])->id;
    }
}
