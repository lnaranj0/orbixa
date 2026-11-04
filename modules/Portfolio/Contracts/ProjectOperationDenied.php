<?php

namespace Modules\Portfolio\Contracts;

use RuntimeException;

/** Project visible to the actor but the operation is not permitted (HTTP 403). */
final class ProjectOperationDenied extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
