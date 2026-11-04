<?php

namespace Modules\IdentityTenancy\Tests\Security;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Modules\Access\Contracts\AccessDenied;
use Modules\IdentityTenancy\Application\AcceptInvitation;
use Modules\IdentityTenancy\Application\CreateOrganization;
use Modules\IdentityTenancy\Application\InviteMember;
use Modules\IdentityTenancy\Application\RevokeMembership;
use Modules\IdentityTenancy\Contracts\InvitationIssued;
use Modules\IdentityTenancy\Contracts\InvitationRejected;
use Modules\IdentityTenancy\Contracts\MembershipChangeDenied;
use Modules\IdentityTenancy\Infrastructure\Persistence\User;
use Modules\Platform\Contracts\Tenancy\OrganizationId;
use Modules\Platform\Contracts\Tenancy\TenantAccessDenied;
use Modules\Platform\Contracts\Tenancy\TenantContext;
use Modules\Platform\Tests\Security\SecurityTestCase;

/**
 * 01 increment 5 on real PostgreSQL: invitations (D8) and immediate
 * revocation.
 */
final class InvitationsAndRevocationTest extends SecurityTestCase
{
    private TenantContext $tenant;

    private User $owner;

    private OrganizationId $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->app->make(TenantContext::class);
        $this->owner = $this->user();
        $this->organization = $this->app->make(CreateOrganization::class)->handle($this->owner, 'Invites Inc');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_valid_invitation_creates_membership_with_the_role_and_only_stores_a_hash(): void
    {
        $invitee = $this->user();
        $issued = $this->invite($this->owner, $invitee->email, 'developer');
        $token = $issued->ticket->reveal();

        $this->tenant->runFor($this->owner, $this->organization, function () use ($issued, $token) {
            $row = DB::selectOne("select encode(token_hash, 'hex') as hash, octet_length(token_hash) as bytes from identity.invitations where id = ?", [$issued->invitationId]);
            $this->assertSame(32, (int) $row->bytes);
            $this->assertSame(hash('sha256', $token), $row->hash);
            $this->assertSame(0, (int) DB::selectOne('select count(*) as total from identity.invitations where row_to_json(invitations)::text like ?', ['%'.$token.'%'])->total);
            $invited = DB::selectOne("select payload::text as payload from events.domain_outbox where event_name = 'organization.member.invited' and aggregate_id = ?", [$issued->invitationId]);
            $this->assertStringNotContainsString('@', $invited->payload, 'Invitation events carry no email.');
        });

        $this->accept($invitee, $token);

        $this->tenant->runFor($invitee, $this->organization, function () use ($invitee) {
            $this->assertTrue(Gate::forUser($invitee)->allows('work.create'));
            $this->assertFalse(Gate::forUser($invitee)->allows('members.manage'));
            $this->assertNotNull(DB::selectOne("select 1 as found from events.domain_outbox where event_name = 'organization.member.joined' and aggregate_id = ?", [$this->membershipId($invitee)]));
        });
    }

    public function test_reused_token_is_rejected(): void
    {
        $invitee = $this->user();
        $token = $this->invite($this->owner, $invitee->email, 'viewer')->ticket->reveal();
        $this->accept($invitee, $token);

        $this->assertRejected(fn () => $this->accept($this->user(), $token));
        $this->assertRejected(fn () => $this->accept($invitee, $token));
    }

    public function test_expired_invitation_is_rejected(): void
    {
        $invitee = $this->user();
        CarbonImmutable::setTestNow(CarbonImmutable::now()->subDays(8));
        $token = $this->invite($this->owner, $invitee->email, 'viewer')->ticket->reveal();
        CarbonImmutable::setTestNow();

        $this->assertRejected(fn () => $this->accept($invitee, $token));
    }

    public function test_invitation_for_another_email_or_unverified_email_is_rejected(): void
    {
        $token = $this->invite($this->owner, 'someone-else-'.Str::uuid().'@example.test', 'viewer')->ticket->reveal();
        $this->assertRejected(fn () => $this->accept($this->user(), $token));

        $unverified = User::factory()->unverified()->create(['email' => Str::uuid().'@example.test']);
        $token = $this->invite($this->owner, $unverified->email, 'viewer')->ticket->reveal();
        $this->assertRejected(fn () => $this->accept($unverified, $token));
    }

    public function test_token_cannot_be_used_for_another_organization(): void
    {
        $invitee = $this->user();
        $token = $this->invite($this->owner, $invitee->email, 'viewer')->ticket->reveal();
        $other = $this->app->make(CreateOrganization::class)->handle($this->user(), 'Other');

        $this->assertRejected(fn () => $this->app->make(AcceptInvitation::class)->handle($invitee, $other, $token));
    }

    public function test_cannot_invite_to_a_role_above_the_inviter(): void
    {
        $admin = $this->addMember('org_admin');
        $developer = $this->addMember('developer');

        $this->assertDenied(fn () => $this->invite($admin, 'x-'.Str::uuid().'@example.test', 'org_owner'));
        $this->assertDenied(fn () => $this->invite($developer, 'y-'.Str::uuid().'@example.test', 'viewer'));
        $this->assertInstanceOf(InvitationIssued::class, $this->invite($admin, 'z-'.Str::uuid().'@example.test', 'developer'));
    }

    public function test_invitation_from_a_since_revoked_inviter_is_rejected(): void
    {
        $admin = $this->addMember('org_admin');
        $invitee = $this->user();
        $token = $this->invite($admin, $invitee->email, 'developer')->ticket->reveal();

        $this->revoke($this->owner, $admin);

        $this->assertRejected(fn () => $this->accept($invitee, $token));
    }

    public function test_attempts_are_throttled(): void
    {
        $invitee = $this->user();
        $token = $this->invite($this->owner, $invitee->email, 'viewer')->ticket->reveal();

        for ($i = 0; $i < AcceptInvitation::MAX_ATTEMPTS; $i++) {
            $this->assertRejected(fn () => $this->accept($invitee, rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=')));
        }

        // Even the valid token is now refused, with the same generic answer.
        $this->assertRejected(fn () => $this->accept($invitee, $token));
    }

    public function test_revocation_is_immediate_and_removes_roles(): void
    {
        $developer = $this->addMember('developer');
        $membershipId = $this->tenant->runFor($developer, $this->organization, fn () => $this->membershipId($developer));

        $this->revoke($this->owner, $developer);

        try {
            $this->tenant->runFor($developer, $this->organization, fn () => $this->fail('must not run'));
            $this->fail('Expected TenantAccessDenied.');
        } catch (TenantAccessDenied) {
            $this->addToAssertionCount(1);
        }

        $this->tenant->runFor($this->owner, $this->organization, function () use ($membershipId) {
            $this->assertSame(0, (int) DB::selectOne('select count(*) as total from access.membership_roles where membership_id = ?', [$membershipId])->total);
            $this->assertNotNull(DB::selectOne("select 1 as found from events.domain_outbox where event_name = 'organization.member.revoked' and aggregate_id = ?", [$membershipId]));
        });
    }

    public function test_revocation_rules(): void
    {
        $admin = $this->addMember('org_admin');
        $developer = $this->addMember('developer');
        // Resolve ids first: a nested context for another actor is (correctly) refused.
        $ownerId = $this->membershipIdOf($this->owner);
        $adminId = $this->membershipIdOf($admin);

        // Admin cannot revoke the owner; a developer cannot revoke anyone; nobody revokes themselves here.
        $this->tenant->runFor($admin, $this->organization, function () use ($admin, $ownerId) {
            $this->expectRevokeFailure(AccessDenied::class, fn () => $this->app->make(RevokeMembership::class)->handle($admin, $ownerId));
        });
        $this->tenant->runFor($developer, $this->organization, function () use ($developer, $adminId) {
            $this->expectRevokeFailure(MembershipChangeDenied::class, fn () => $this->app->make(RevokeMembership::class)->handle($developer, $adminId));
        });
        $this->tenant->runFor($this->owner, $this->organization, function () use ($ownerId) {
            $this->expectRevokeFailure(MembershipChangeDenied::class, fn () => $this->app->make(RevokeMembership::class)->handle($this->owner, $ownerId));
        });
    }

    public function test_revoked_member_can_rejoin_with_a_new_invitation(): void
    {
        $member = $this->addMember('developer');
        $this->revoke($this->owner, $member);

        $this->accept($member, $this->invite($this->owner, $member->email, 'viewer')->ticket->reveal());

        $this->tenant->runFor($member, $this->organization, fn () => $this->assertFalse(Gate::forUser($member)->allows('work.create')));
    }

    private function invite(User $inviter, string $email, string $role): InvitationIssued
    {
        return $this->tenant->runFor($inviter, $this->organization, fn () => $this->app->make(InviteMember::class)->handle($inviter, $email, $role));
    }

    private function accept(User $user, string $token): string
    {
        return $this->app->make(AcceptInvitation::class)->handle($user, $this->organization, $token);
    }

    private function revoke(User $actor, User $target): void
    {
        $membershipId = $this->membershipIdOf($target);
        $this->tenant->runFor($actor, $this->organization, fn () => $this->app->make(RevokeMembership::class)->handle($actor, $membershipId));
    }

    private function addMember(string $role): User
    {
        $user = $this->user();
        $this->accept($user, $this->invite($this->owner, $user->email, $role)->ticket->reveal());

        return $user;
    }

    private function membershipIdOf(User $user): string
    {
        return $this->tenant->runFor($this->owner, $this->organization, fn () => $this->membershipId($user));
    }

    private function membershipId(User $user): string
    {
        return (string) DB::selectOne('select id::text as id from identity.organization_memberships where user_id = ?', [$user->getKey()])->id;
    }

    private function user(): User
    {
        return User::factory()->create(['email' => Str::uuid().'@example.test']);
    }

    private function assertRejected(\Closure $operation): void
    {
        try {
            $operation();
            $this->fail('Expected InvitationRejected.');
        } catch (InvitationRejected $rejected) {
            $this->assertSame('This invitation is not valid.', $rejected->getMessage());
        }
    }

    private function assertDenied(\Closure $operation): void
    {
        try {
            $operation();
            $this->fail('Expected MembershipChangeDenied.');
        } catch (MembershipChangeDenied) {
            $this->addToAssertionCount(1);
        }
    }

    /**
     * @param  class-string<\Throwable>  $expected
     */
    private function expectRevokeFailure(string $expected, \Closure $operation): void
    {
        try {
            DB::transaction($operation);
            $this->fail("Expected {$expected}.");
        } catch (\Throwable $exception) {
            $this->assertInstanceOf($expected, $exception);
        }
    }
}
