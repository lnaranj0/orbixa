<?php

namespace Modules\Teams\Contracts;

use RuntimeException;

/** Unknown, foreign, revoked or not assigned membership (HTTP 404). */
final class TeamMemberNotFound extends RuntimeException
{
    public static function make(): self
    {
        return new self('Member not found.');
    }
}
