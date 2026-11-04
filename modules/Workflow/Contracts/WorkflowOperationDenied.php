<?php

namespace Modules\Workflow\Contracts;

use RuntimeException;

/** Visible project, but the actor may not configure its workflow (HTTP 403). */
final class WorkflowOperationDenied extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
