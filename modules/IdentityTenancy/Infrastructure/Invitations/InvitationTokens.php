<?php

namespace Modules\IdentityTenancy\Infrastructure\Invitations;

use Modules\Platform\Contracts\Tenancy\AdmissionTicket;
use SensitiveParameter;

final class InvitationTokens
{
    /** 32 random bytes (256 bits), base64url without padding (43 chars). */
    public static function generate(): AdmissionTicket
    {
        return AdmissionTicket::fromSecret(rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='));
    }

    public static function isWellFormed(#[SensitiveParameter] string $token): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{43}$/', $token) === 1;
    }

    /** Hex of sha256(token), for decode(?, 'hex') in SQL. */
    public static function hashHex(AdmissionTicket $ticket): string
    {
        return hash('sha256', $ticket->reveal());
    }

    public static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }
}
