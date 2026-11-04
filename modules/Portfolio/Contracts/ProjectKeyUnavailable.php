<?php

namespace Modules\Portfolio\Contracts;

use RuntimeException;

/**
 * Generic duplicate-key error (D11): identical whether the existing project
 * is visible or hidden. Enumeration risk accepted and rate limited.
 */
final class ProjectKeyUnavailable extends RuntimeException
{
    public static function make(): self
    {
        return new self('This project key cannot be used.');
    }
}
