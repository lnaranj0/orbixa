<?php

namespace Modules\Planning\Contracts;

use RuntimeException;

/** Visible project, but the actor may not manage its planning (HTTP 403). */
final class PlanningOperationDenied extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
