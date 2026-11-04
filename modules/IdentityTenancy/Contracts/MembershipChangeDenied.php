<?php

namespace Modules\IdentityTenancy\Contracts;

use RuntimeException;

/** Membership operation in the active organization without permission (HTTP 403). */
final class MembershipChangeDenied extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
