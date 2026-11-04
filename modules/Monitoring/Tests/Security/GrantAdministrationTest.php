<?php

namespace Modules\Monitoring\Tests\Security;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\IdentityTenancy\Application\AcceptInvitation;
use Modules\IdentityTenancy\Application\CreateOrganization;
use Modules\IdentityTenancy\Application\InviteMember;
use Modules\IdentityTenancy\Application\RevokeMembership;
use Modules\IdentityTenancy\Infrastructure\Persistence\User;
use Modules\Monitoring\Application\ChangeGrant;
use Modules\Monitoring\Application\IssueGrant;
use Modules\Monitoring\Contracts\GrantNotFound;
use Modules\Monitoring\Contracts\GrantOperationDenied;
use Modules\Monitoring\Contracts\GrantVersionConflict;
use Modules\Platform\Contracts\Tenancy\OrganizationId;
use Modules\Platform\Contracts\Tenancy\TenantContext;
use Modules\Platform\Contracts\Validation\InputRejected;
use Modules\Platform\Tests\Security\SecurityTestCase;
use Modules\Teams\Application\ArchiveTeam;
use Modules\Teams\Application\CreateTeam;

/**
 * 04 increment 4: grant administration (spec "Administración, vigencia y
 * revocación", "Cross-tenant y escalamiento", "Concesión sin escalada").
 */
final class GrantAdministrationTest extends SecurityTestCase
{
    private TenantContext $tenant;

    private User $owner;

    private OrganizationId $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->app->make(TenantContext::class);
        $this->owner = $this->user();
        $this->organization = $this->app->make(CreateOrganization::class)->handle($this->owner, 'Grants Inc');
    }

    public function test_owner_issues_grants_with_history_and_events(): void
    {
        $viewer = $this->member('viewer');
        $observed = $this->member('developer');

        $this->as($this->owner, function () use ($viewer, $observed) {
            $team = $this->app->make(CreateTeam::class)->handle($this->owner, 'Observed team');
            $issue = $this->app->make(IssueGrant::class);
            $ids = [
                $issue->handle($this->owner, $this->input($viewer, 'user', ['target_membership_id' => $this->membershipId($observed)]))->grantId,
                $issue->handle($this->owner, $this->input($viewer, 'team', ['target_team_id' => $team, 'include_descendants' => true]))->grantId,
                $issue->handle($this->owner, $this->input($viewer, 'organization', ['visibility_level' => 'restricted']))->grantId,
            ];
            foreach ($ids as $id) {
                $this->assertIsString($id);
                $this->assertSame(1, $this->rows('monitoring.grant_events', "grant_id = '{$id}' and type = 'created'"));
                $this->assertSame(1, $this->rows('events.domain_outbox', "aggregate_id = '{$id}' and event_name = 'monitoring.grant.created'"));
            }
            $payloads = (string) DB::selectOne("select string_agg(payload::text, ' ') as all from events.domain_outbox where event_name like 'monitoring.%'")->all;
            $this->assertStringNotContainsString('Follow-up', $payloads);
        });
    }

    public function test_escalation_is_refused(): void
    {
        $viewer = $this->member('viewer');
        $developer = $this->member('developer');
        $admin = $this->member('org_admin');

        $this->as($developer, fn () => $this->assertFailsWith(GrantOperationDenied::class, fn () => $this->app->make(IssueGrant::class)->handle($developer, $this->input($viewer, 'organization'))));
        $this->as($viewer, fn () => $this->assertFailsWith(GrantOperationDenied::class, fn () => $this->app->make(IssueGrant::class)->handle($viewer, $this->input($viewer, 'organization'))));
        $adminMembership = $this->as($this->owner, fn () => $this->membershipId($admin));
        $this->as($admin, fn () => $this->assertFailsWith(GrantOperationDenied::class, fn () => $this->app->make(IssueGrant::class)->handle($admin, ['viewer_membership_id' => $adminMembership, 'scope_type' => 'organization', 'reason' => 'Self', 'expires_at' => $this->expires()])));
        $this->as($this->owner, function () use ($viewer) {
            $issue = $this->app->make(IssueGrant::class);
            $this->assertFailsWith(InputRejected::class, fn () => $issue->handle($this->owner, $this->input($viewer, 'organization', ['expires_at' => (new \DateTimeImmutable('+400 days'))->format(DATE_ATOM)])));
            $this->assertFailsWith(InputRejected::class, fn () => $issue->handle($this->owner, $this->input($viewer, 'organization', ['reason' => '   '])));
            $this->assertFailsWith(InputRejected::class, fn () => $issue->handle($this->owner, $this->input($viewer, 'project')));
        });
    }

    public function test_unusable_viewers_and_targets_are_rejected_alike_and_audited(): void
    {
        $viewer = $this->member('viewer');
        $developer = $this->member('developer'); // no monitoring.view
        $revoked = $this->member('developer');
        $stranger = $this->user();
        $other = $this->app->make(CreateOrganization::class)->handle($stranger, 'Other Inc');
        [$foreignMembership, $foreignTeam] = $this->tenant->runFor($stranger, $other, fn () => [
            (string) DB::selectOne('select id::text as id from identity.organization_memberships where user_id = ?', [$stranger->getKey()])->id,
            $this->app->make(CreateTeam::class)->handle($stranger, 'Foreign team'),
        ]);
        $revokedMembership = $this->as($this->owner, fn () => $this->membershipId($revoked));
        $this->as($this->owner, fn () => $this->app->make(RevokeMembership::class)->handle($this->owner, $revokedMembership));

        $archived = $this->as($this->owner, function () {
            $team = $this->app->make(CreateTeam::class)->handle($this->owner, 'Old');
            $this->app->make(ArchiveTeam::class)->handle($this->owner, $team);

            return $team;
        });

        $cases = [
            ['viewer_membership_id' => $this->as($this->owner, fn () => $this->membershipId($developer)), 'scope_type' => 'organization'],
            ['viewer_membership_id' => $foreignMembership, 'scope_type' => 'organization'],
            ['viewer_membership_id' => (string) Str::uuid(), 'scope_type' => 'organization'],
            ['scope_type' => 'user', 'target_membership_id' => $foreignMembership],
            ['scope_type' => 'user', 'target_membership_id' => $revokedMembership],
            ['scope_type' => 'user', 'target_membership_id' => 'not-a-uuid'],
            ['scope_type' => 'team', 'target_team_id' => $foreignTeam],
            ['scope_type' => 'team', 'target_team_id' => $archived],
            ['scope_type' => 'team', 'target_team_id' => (string) Str::uuid()],
        ];
        foreach ($cases as $case) {
            $result = $this->as($this->owner, fn () => $this->app->make(IssueGrant::class)->handle($this->owner, $case + $this->input($viewer, $case['scope_type'])));
            $this->assertTrue($result->wasRejected(), json_encode($case, JSON_THROW_ON_ERROR));
        }

        // Each attempt committed its audit row, with no data of the other organization.
        $this->as($this->owner, function () use ($cases) {
            $this->assertSame(count($cases), $this->rows('monitoring.grant_events', "type = 'rejected'"));
            $this->assertSame(0, $this->rows('monitoring.grants', 'true'));
            $changes = (string) DB::selectOne("select string_agg(changes::text, ' ') as all from monitoring.grant_events")->all;
            $this->assertStringNotContainsString('Foreign', $changes);
            $this->assertStringNotContainsString('@', $changes);
        });
    }

    public function test_update_and_revoke(): void
    {
        $viewer = $this->member('viewer');
        $observed = $this->member('developer');

        $this->as($this->owner, function () use ($viewer, $observed) {
            $grant = (string) $this->app->make(IssueGrant::class)->handle($this->owner, $this->input($viewer, 'user', ['target_membership_id' => $this->membershipId($observed)]))->grantId;
            $change = $this->app->make(ChangeGrant::class);

            $this->assertSame(1, $change->update($this->owner, $grant, 0, ['reason' => 'Quarterly review', 'visibility_level' => 'restricted']));
            $this->assertFailsWith(GrantVersionConflict::class, fn () => $change->update($this->owner, $grant, 0, ['reason' => 'Stale']));
            $this->assertFailsWith(InputRejected::class, fn () => $change->update($this->owner, $grant, 1, ['include_descendants' => true]));
            $this->assertFailsWith(InputRejected::class, fn () => $change->update($this->owner, $grant, 1, ['expires_at' => (new \DateTimeImmutable('+500 days'))->format(DATE_ATOM)]));

            $change->revoke($this->owner, $grant, 'Project ended');
            $this->assertFailsWith(GrantNotFound::class, fn () => $change->revoke($this->owner, $grant));
            $this->assertFailsWith(GrantNotFound::class, fn () => $change->update($this->owner, $grant, 2, ['reason' => 'Too late']));
            $this->assertSame(['created', 'updated', 'revoked'], array_column(DB::select('select type from monitoring.grant_events where grant_id = ? order by occurred_at, id', [$grant]), 'type'));
        });
    }

    public function test_revoking_a_membership_closes_its_grants(): void
    {
        $viewer = $this->member('viewer');
        $observed = $this->member('developer');
        $other = $this->member('viewer');

        [$asViewer, $asTarget] = $this->as($this->owner, function () use ($viewer, $observed, $other) {
            $issue = $this->app->make(IssueGrant::class);

            return [
                (string) $issue->handle($this->owner, $this->input($viewer, 'organization'))->grantId,
                (string) $issue->handle($this->owner, $this->input($other, 'user', ['target_membership_id' => $this->membershipId($observed)]))->grantId,
            ];
        });
        $this->as($this->owner, function () use ($viewer, $observed) {
            $this->app->make(RevokeMembership::class)->handle($this->owner, $this->membershipId($viewer));
            $this->app->make(RevokeMembership::class)->handle($this->owner, $this->membershipId($observed));
        });

        $this->as($this->owner, function () use ($asViewer, $asTarget) {
            foreach ([$asViewer, $asTarget] as $grant) {
                $this->assertSame('membership_revoked', DB::selectOne('select revoked_reason from monitoring.grants where id = ?', [$grant])->revoked_reason);
                $this->assertSame(1, $this->rows('monitoring.grant_events', "grant_id = '{$grant}' and type = 'membership_revoked'"));
            }
        });
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
