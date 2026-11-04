<?php

namespace Modules\IdentityTenancy\Application;

use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\DatabaseManager;
use Modules\IdentityTenancy\Contracts\InvitationRejected;
use Modules\IdentityTenancy\Contracts\OrganizationLifecycle;
use Modules\IdentityTenancy\Infrastructure\Invitations\InvitationTokens;
use Modules\IdentityTenancy\Infrastructure\Membership\ActorIdentifier;
use Modules\Platform\Contracts\Events\ActorReference;
use Modules\Platform\Contracts\Events\DomainEvent;
use Modules\Platform\Contracts\Events\DomainEventRecorder;
use Modules\Platform\Contracts\Tenancy\AdmissionTicket;
use Modules\Platform\Contracts\Tenancy\OrganizationId;
use Modules\Platform\Contracts\Tenancy\TenantAccessDenied;
use Modules\Platform\Contracts\Tenancy\TenantContext;
use RuntimeException;
use SensitiveParameter;

/**
 * Spec "Invitaciones": accepts through TenantContext::runForAdmission (the
 * admission check and the single-use claim happen in one transaction).
 * Every failure is the same generic InvitationRejected, and attempts are
 * throttled per user and per token.
 */
final class AcceptInvitation
{
    public const MAX_ATTEMPTS = 5;

    public const DECAY_SECONDS = 900;

    /**
     * @param  iterable<OrganizationLifecycle>  $lifecycle
     */
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly DomainEventRecorder $events,
        private readonly DatabaseManager $db,
        private readonly RateLimiter $limiter,
        private readonly iterable $lifecycle = [],
    ) {}

    public function handle(Authenticatable $actor, OrganizationId $organization, #[SensitiveParameter] string $token): string
    {
        $userId = ActorIdentifier::of($actor);
        $keys = ['invitation-accept:user:'.($userId ?? 'anonymous'), 'invitation-accept:token:'.hash('sha256', $token)];

        foreach ($keys as $key) {
            if ($this->limiter->tooManyAttempts($key, self::MAX_ATTEMPTS)) {
                throw InvitationRejected::generic();
            }
        }

        try {
            if ($userId === null || ! InvitationTokens::isWellFormed($token)) {
                throw InvitationRejected::generic();
            }

            $ticket = AdmissionTicket::fromSecret($token);

            $membershipId = $this->tenant->runForAdmission($actor, $organization, $ticket, fn () => $this->admit($organization, $userId, $ticket));
        } catch (InvitationRejected|TenantAccessDenied) {
            foreach ($keys as $key) {
                $this->limiter->hit($key, self::DECAY_SECONDS);
            }

            throw InvitationRejected::generic();
        }

        $this->limiter->clear($keys[0]);

        return $membershipId;
    }

    private function admit(OrganizationId $organization, string $userId, AdmissionTicket $ticket): string
    {
        $connection = $this->db->connection();

        $existing = $connection->selectOne(
            'select id::text as id, status from identity.organization_memberships where user_id = ?::uuid for update',
            [$userId]
        );

        if ($existing !== null && ((array) $existing)['status'] === 'active') {
            throw InvitationRejected::generic();
        }

        $membershipId = $existing !== null
            ? $this->reactivate((string) ((array) $existing)['id'])
            : (string) ((array) $connection->selectOne(
                'insert into identity.organization_memberships (organization_id, user_id)
                 values (platform.current_organization_id(), ?::uuid) returning id::text as id',
                [$userId]
            ))['id'];

        $email = (string) ((array) $connection->selectOne('select email from identity.users where id = ?::uuid', [$userId]))['email'];

        // Atomic single use: only one transaction can move it out of "pending".
        $claimed = $connection->selectOne(
            "update identity.invitations
                set accepted_at = now(), accepted_membership_id = ?::uuid
              where token_hash = decode(?, 'hex') and email_normalized = ?
                and accepted_at is null and revoked_at is null and expires_at > now()
              returning id::text as id, role_key, invited_by_membership_id::text as invited_by",
            [$membershipId, InvitationTokens::hashHex($ticket), InvitationTokens::normalizeEmail($email)]
        );

        if ($claimed === null) {
            throw InvitationRejected::generic();
        }

        $invitation = (array) $claimed;

        try {
            foreach ($this->lifecycle as $hook) {
                $hook->membershipJoined($organization, $membershipId, (string) $invitation['role_key'], (string) $invitation['invited_by']);
            }
        } catch (RuntimeException) {
            // A veto (e.g. the inviter can no longer grant the role) is
            // indistinguishable from any other invalid invitation.
            throw InvitationRejected::generic();
        }

        $this->events->record(
            new DomainEvent('organization.member.joined', 1, 'membership', $membershipId, [
                'invitation_id' => (string) $invitation['id'],
                'role_key' => (string) $invitation['role_key'],
            ]),
            ActorReference::human($userId),
        );

        return $membershipId;
    }

    private function reactivate(string $membershipId): string
    {
        $this->db->connection()->update(
            "update identity.organization_memberships
                set status = 'active', revoked_at = null, revoked_by_membership_id = null, lock_version = lock_version + 1
              where id = ?::uuid",
            [$membershipId]
        );

        return $membershipId;
    }
}
