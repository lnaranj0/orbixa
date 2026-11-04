<?php

namespace Modules\Portfolio\Contracts;

use RuntimeException;

/** Optimistic concurrency: the project changed since it was read (HTTP 409). */
final class ProjectVersionConflict extends RuntimeException
{
    public static function make(): self
    {
        return new self('The project was modified by someone else.');
    }
}
