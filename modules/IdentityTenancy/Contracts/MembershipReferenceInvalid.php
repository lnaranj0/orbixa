<?php

namespace Modules\IdentityTenancy\Contracts;

use RuntimeException;

/**
 * A membership referenced in a request body (to add it to a team, project,
 * ...) is unknown, inactive or from another organization (D10: HTTP 422,
 * the same response in every case so it does not reveal which exist).
 */
final class MembershipReferenceInvalid extends RuntimeException
{
    public static function make(): self
    {
        return new self('This membership cannot be used.');
    }
}
