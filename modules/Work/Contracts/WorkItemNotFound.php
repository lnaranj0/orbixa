<?php

namespace Modules\Work\Contracts;

use RuntimeException;

/** Unknown, foreign or not visible work item (HTTP 404). */
final class WorkItemNotFound extends RuntimeException
{
    public static function make(): self
    {
        return new self('Work item not found.');
    }
}
