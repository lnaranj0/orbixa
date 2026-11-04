<?php

namespace Modules\Platform\Contracts\Tenancy;

use LogicException;

final class TenantContextRequired extends LogicException
{
    public static function forOperation(string $operation): self
    {
        return new self("{$operation} requires an active tenant context and transaction.");
    }
}
