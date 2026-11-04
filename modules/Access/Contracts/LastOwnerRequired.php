<?php

namespace Modules\Access\Contracts;

use RuntimeException;

final class LastOwnerRequired extends RuntimeException
{
    public static function make(): self
    {
        return new self('An organization must keep at least one org_owner.');
    }
}
