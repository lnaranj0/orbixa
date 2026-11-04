<?php

namespace Modules\IdentityTenancy\Infrastructure\Invitations;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\DatabaseManager;
use Modules\IdentityTenancy\Infrastructure\Membership\ActorIdentifier;
use Modules\Platform\Contracts\Tenancy\AdmissionTicket;
use Modules\Platform\Contracts\Tenancy\AdmissionVerifier;
use Modules\Platform\Contracts\Tenancy\OrganizationId;

/**
 * Admits the actor only with a pending, unexpired invitation of that
 * organization for the actor's VERIFIED email (narrow definer function).
 */
final class PostgresAdmissionVerifier implements AdmissionVerifier
{
    public function __construct(private readonly DatabaseManager $db) {}

    public function canAdmit(Authenticatable $actor, OrganizationId $organization, AdmissionTicket $ticket): bool
    {
        $userId = ActorIdentifier::of($actor);

        if ($userId === null || ! InvitationTokens::isWellFormed($ticket->reveal())) {
            return false;
        }

        $user = $this->db->connection()->selectOne(
            'select email, email_verified_at from identity.users where id = ?::uuid',
            [$userId]
        );

        if ($user === null || ((array) $user)['email_verified_at'] === null) {
            return false;
        }

        return (bool) ((array) $this->db->connection()->selectOne(
            "select identity.admissible_invitation(decode(?, 'hex'), ?::uuid, ?) as admissible",
            [InvitationTokens::hashHex($ticket), $organization->value, InvitationTokens::normalizeEmail((string) ((array) $user)['email'])]
        ))['admissible'];
    }
}
