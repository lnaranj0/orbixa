<?php

namespace Modules\Monitoring\Contracts;

use RuntimeException;

/** The viewer or target cannot be used (HTTP 422). The same answer for a missing, inactive, foreign or ineligible reference, so it reveals nothing about other organizations; the attempt is audited. */
final class GrantRejected extends RuntimeException
{
    public static function make(): self
    {
        return new self('This grant cannot be issued.');
    }
}
