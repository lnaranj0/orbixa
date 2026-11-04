<?php

namespace Modules\Work\Contracts;

use RuntimeException;

/** Work item visible to the actor but the operation is not permitted (HTTP 403). */
final class WorkOperationDenied extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
