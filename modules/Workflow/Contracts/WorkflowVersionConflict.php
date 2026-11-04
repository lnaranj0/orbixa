<?php

namespace Modules\Workflow\Contracts;

use RuntimeException;

/** The workflow changed since it was read (HTTP 409). */
final class WorkflowVersionConflict extends RuntimeException
{
    public static function make(): self
    {
        return new self('The workflow was modified by someone else.');
    }
}
