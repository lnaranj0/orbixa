<?php

namespace Modules\Work\Contracts;

use RuntimeException;

/** No active assignment, label, team link or dependency to remove (HTTP 404). */
final class WorkRelationNotFound extends RuntimeException
{
    public static function make(): self
    {
        return new self('Work item relation not found.');
    }
}
