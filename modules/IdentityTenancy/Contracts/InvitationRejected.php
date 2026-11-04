<?php

namespace Modules\IdentityTenancy\Contracts;

use RuntimeException;

/**
 * Single generic failure for invitation acceptance: unknown, used, expired,
 * revoked, other email, throttled or no longer grantable all look the same.
 */
final class InvitationRejected extends RuntimeException
{
    public static function generic(): self
    {
        return new self('This invitation is not valid.');
    }
}
