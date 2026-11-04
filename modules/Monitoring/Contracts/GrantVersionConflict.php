<?php

namespace Modules\Monitoring\Contracts;

use RuntimeException;

/** Optimistic concurrency: the grant changed since it was read (HTTP 409). */
final class GrantVersionConflict extends RuntimeException
{
    public static function make(): self
    {
        return new self('The grant was modified by someone else.');
    }
}
