<?php

namespace Modules\Work\Contracts;

use RuntimeException;

/** Optimistic concurrency: the work item changed since it was read (HTTP 409). */
final class WorkVersionConflict extends RuntimeException
{
    public static function make(): self
    {
        return new self('The work item was modified by someone else.');
    }
}
