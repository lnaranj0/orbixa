<?php

namespace Modules\Teams\Contracts;

use RuntimeException;

/** Team visible to the actor but the operation is not permitted (HTTP 403). */
final class TeamOperationDenied extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
