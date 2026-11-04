<?php

namespace Modules\Teams\Contracts;

use RuntimeException;

/** Unknown, foreign or not visible team, indistinguishable (HTTP 404). */
final class TeamNotFound extends RuntimeException
{
    public static function make(): self
    {
        return new self('Team not found.');
    }
}
