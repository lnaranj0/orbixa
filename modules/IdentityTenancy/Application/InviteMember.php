<?php

namespace Modules\IdentityTenancy\Application;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Modules\IdentityTenancy\Contracts\CurrentMembership;
use Modules\IdentityTenancy\Contracts\InvitationIssued;
use Modules\IdentityTenancy\Contracts\MembershipChangeDenied;
use Modules\IdentityTenancy\Contracts\RoleGrantGuard;
use Modules\IdentityTenancy\Infrastructure\Invitations\InvitationTokens;
use Modules\Platform\Contracts\Events\ActorReference;
use Modules\Platform\Contracts\Events\DomainEvent;
use Modules\Platform\Contracts\Events\DomainEventRecorder;
use Modules\Platform\Contracts\Tenancy\TenantContext;
use Modules\Platform\Contracts\Tenancy\TenantContextRequired;

/**
 * Spec "Invitaciones": issues a single-use, 7-day invitation bound to an
 * email, replacing any pending one for that email. Only the token hash is
 * stored; the event carries no email.
 */
final class InviteMember
{
    public const TTL_DAYS = 7;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly CurrentMembership $memberships,
        private readonly RoleGrantGuard $guard,
        private readonly DomainEventRecorder $events,
        private readonly DatabaseManager $db,
    ) {}

    public function handle(Authenticatable $inviter, string $email, string $roleKey): InvitationIssued
    {
        if ($this->tenant->current() === null) {
            throw TenantContextRequired::forOperation('Inviting members');
        }

        $email = InvitationTokens::normalizeEmail($email);
        if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('A valid email is required.');
        }

        $inviterMembershipId = $this->memberships->idFor($inviter) ?? throw MembershipChangeDenied::because('No active membership.');

        if (! $this->guard->canInvite($inviter, $roleKey)) {
            throw MembershipChangeDenied::because('Not allowed to invite with this role.');
        }

        $connection = $this->db->connection();

        return $connection->transaction(function () use ($connection, $inviter, $inviterMembershipId, $email, $roleKey) {
            $connection->update(
                'update identity.invitations set revoked_at = now(), revoked_by_membership_id = ?::uuid
                  where email_normalized = ? and accepted_at is null and revoked_at is null',
                [$inviterMembershipId, $email]
            );

            $ticket = InvitationTokens::generate();
            $now = CarbonImmutable::now();
            $expiresAt = $now->addDays(self::TTL_DAYS);

            $invitationId = (string) ((array) $connection->selectOne(
                "insert into identity.invitations
                    (organization_id, email_normalized, role_key, token_hash, invited_by_membership_id, created_at, expires_at)
                 values (platform.current_organization_id(), ?, ?, decode(?, 'hex'), ?::uuid, ?, ?)
                 returning id::text as id",
                [$email, $roleKey, InvitationTokens::hashHex($ticket), $inviterMembershipId, $now->toIso8601String(), $expiresAt->toIso8601String()]
            ))['id'];

            $this->events->record(
                new DomainEvent('organization.member.invited', 1, 'invitation', $invitationId, [
                    'role_key' => $roleKey,
                    'invited_by_membership_id' => $inviterMembershipId,
                ]),
                ActorReference::human((string) $inviter->getAuthIdentifier()),
            );

            return new InvitationIssued($invitationId, $expiresAt->toDateTimeImmutable(), $ticket);
        });
    }
}
