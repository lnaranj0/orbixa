<?php

namespace Modules\Platform\Contracts\Tenancy;

use RuntimeException;

final class TenantAccessDenied extends RuntimeException
{
    public static function forActor(): self
    {
        // Deliberately generic: never reveal whether the organization exists.
        return new self('Organization context denied.');
    }
}
