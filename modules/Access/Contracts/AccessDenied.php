<?php

namespace Modules\Access\Contracts;

use RuntimeException;

/** Operation on a resource of the active organization without permission (HTTP 403). */
final class AccessDenied extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
