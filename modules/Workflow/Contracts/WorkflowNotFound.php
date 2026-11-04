<?php

namespace Modules\Workflow\Contracts;

use RuntimeException;

/** Unknown, foreign or not visible project, status or transition (HTTP 404). */
final class WorkflowNotFound extends RuntimeException
{
    public static function make(): self
    {
        return new self('Not found.');
    }
}
