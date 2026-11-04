<?php

namespace Modules\Monitoring\Tests\Security;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\IdentityTenancy\Application\AcceptInvitation;
use Modules\IdentityTenancy\Application\CreateOrganization;
use Modules\IdentityTenancy\Application\InviteMember;
use Modules\IdentityTenancy\Infrastructure\Persistence\User;
use Modules\Monitoring\Application\ChangeGrant;
use Modules\Monitoring\Application\IssueGrant;
use Modules\Platform\Contracts\Tenancy\OrganizationId;
use Modules\Platform\Contracts\Tenancy\TenantContext;
use Modules\Platform\Contracts\Validation\InputRejected;
use Modules\Platform\Tests\Security\SecurityTestCase;
use Modules\Teams\Application\CreateTeam;

/**
 * Regressions for the 04 security review (M-1, M-2, B1-B3).
 */
final class MonitoringReviewTest extends SecurityTestCase
{
    private TenantContext $tenant;

    private User $owner;

    private OrganizationId $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->app->make(TenantContext::class);
        $this->owner = $this->user();
        $this->organization = $this->app->make(CreateOrganization::class)->handle($this->owner, 'Review Monitoring');
    }

    public function test_m2_revoked_grants_are_frozen_and_expired_ones_are_not_revived(): void
    {
        $viewer = $this->member('viewer');
        $this->as($this->owner, function () use ($viewer) {
            $issue = $this->app->make(IssueGrant::class);
            $revoked = (string) $issue->handle($this->owner, $this->input($viewer, 'organization'))->grantId;
            $this->app->make(ChangeGrant::class)->revoke($this->owner, $revoked);
            $this->assertSqlState('23514', fn (Connection $db) => $db->update('update monitoring.grants set revoked_at = null, revoked_by_membership_id = null, revoked_reason = null where id = ?', [$revoked]));
            $this->assertSqlState('23514', fn (Connection $db) => $db->update("update monitoring.grants set reason = 'Edited' where id = ?", [$revoked]));

            $expiring = (string) $issue->handle($this->owner, $this->input($viewer, 'organization'))->grantId;
            DB::update("update monitoring.grants set expires_at = starts_at + interval '1 second' where id = ?", [$expiring]);
        });
        sleep(2);
        $this->as($this->owner, function () {
            $expired = (string) DB::selectOne('select id::text as id from monitoring.grants where revoked_at is null')->id;
            $this->assertFailsWith(InputRejected::class, fn () => $this->app->make(ChangeGrant::class)->update($this->owner, $expired, 1, ['expires_at' => (new \DateTimeImmutable('+20 days'))->format(DATE_ATOM)]));
        });
    }

    public function test_m1_active_grants_per_viewer_are_capped(): void
    {
        $viewer = $this->member('viewer');
        $this->as($this->owner, function () use ($viewer) {
            $issue = $this->app->make(IssueGrant::class);
            for ($i = 0; $i < IssueGrant::MAX_ACTIVE_PER_VIEWER; $i++) {
                $this->assertFalse($issue->handle($this->owner, $this->input($viewer, 'organization'))->wasRejected());
            }
            $this->assertFailsWith(InputRejected::class, fn () => $issue->handle($this->owner, $this->input($viewer, 'organization')));
        });
    }

    public function test_b1_b2_b3_validity_and_types_are_422_not_500(): void
    {
        $viewer = $this->member('viewer');
        $this->as($this->owner, function () use ($viewer) {
            $issue = $this->app->make(IssueGrant::class);
            $this->assertFailsWith(InputRejected::class, fn () => $issue->handle($this->owner, $this->input($viewer, 'organization', ['starts_at' => (new \DateTimeImmutable('-2 days'))->format(DATE_ATOM)])));

            $future = (string) $issue->handle($this->owner, $this->input($viewer, 'organization', [
                'starts_at' => (new \DateTimeImmutable('+10 days'))->format(DATE_ATOM),
                'expires_at' => (new \DateTimeImmutable('+40 days'))->format(DATE_ATOM),
            ]))->grantId;
            $this->assertFailsWith(InputRejected::class, fn () => $this->app->make(ChangeGrant::class)->update($this->owner, $future, 0, ['expires_at' => (new \DateTimeImmutable('+5 days'))->format(DATE_ATOM)]));
        });

        $team = $this->as($this->owner, fn () => $this->app->make(CreateTeam::class)->handle($this->owner, 'Typed'));
        $grant = $this->as($this->owner, fn () => (string) $this->app->make(IssueGrant::class)->handle($this->owner, $this->input($viewer, 'team', ['target_team_id' => $team]))->grantId);
        $this->actingAs($this->owner)
            ->patchJson("/api/v1/organizations/{$this->organization->value}/monitoring/grants/{$grant}", ['expected_version' => 0, 'include_descendants' => 1])
            ->assertOk()->assertJsonPath('data.include_descendants', true);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{viewer_membership_id: string, scope_type: string, reason: string, expires_at: string}
     */
    private function input(User $viewer, string $scope, array $overrides = []): array
    {
        /** @var array{viewer_membership_id: string, scope_type: string, reason: string, expires_at: string} */
        return $overrides + [
            'viewer_membership_id' => $this->membershipIdAnywhere($viewer),
            'scope_type' => $scope,
            'reason' => 'Follow-up',
            'expires_at' => $this->expires(),
        ];
    }

    private function expires(): string
    {
        return (new \DateTimeImmutable('+30 days'))->format(DATE_ATOM);
    }

    private function rows(string $table, string $where): int
    {
        return (int) DB::selectOne("select count(*) as total from {$table} where {$where}")->total;
    }

    /** Membership id whether or not a tenant context is already open. */
    private function membershipIdAnywhere(User $user): string
    {
        $tenant = $this->tenant->current();

        return $tenant === null ? $this->as($this->owner, fn () => $this->membershipId($user)) : $this->membershipId($user);
    }

    private function as(User $actor, \Closure $callback): mixed
    {
        return $this->tenant->runFor($actor, $this->organization, $callback);
    }

    private function user(): User
    {
        return User::factory()->create(['email' => Str::uuid().'@example.test']);
    }

    private function member(string $role): User
    {
        $user = $this->user();
        $ticket = $this->as($this->owner, fn () => $this->app->make(InviteMember::class)->handle($this->owner, $user->email, $role))->ticket;
        $this->app->make(AcceptInvitation::class)->handle($user, $this->organization, $ticket->reveal());

        return $user;
    }

    private function membershipId(User $user): string
    {
        return (string) DB::selectOne('select id::text as id from identity.organization_memberships where user_id = ?', [$user->getKey()])->id;
    }

    private function events(string $name, string $aggregateId): int
    {
        return (int) DB::selectOne('select count(*) as total from events.domain_outbox where event_name = ? and aggregate_id = ?', [$name, $aggregateId])->total;
    }

    /**
     * @param  class-string<\Throwable>  $expected
     */
    private function assertFailsWith(string $expected, \Closure $operation): void
    {
        try {
            DB::transaction($operation);
            $this->fail("Expected {$expected}.");
        } catch (\Throwable $exception) {
            $this->assertInstanceOf($expected, $exception, $exception->getMessage());
        }
    }
}
