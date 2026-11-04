<?php

namespace Modules\Monitoring\Contracts;

use RuntimeException;

/** Unknown, foreign or already revoked grant (HTTP 404). */
final class GrantNotFound extends RuntimeException
{
    public static function make(): self
    {
        return new self('Monitoring grant not found.');
    }
}
