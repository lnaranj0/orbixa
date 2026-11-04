<?php

namespace Modules\Monitoring\Contracts;

use RuntimeException;

/** The actor may not administer this grant (HTTP 403). */
final class GrantOperationDenied extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
