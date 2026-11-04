<?php

namespace Modules\Platform\Contracts\Tenancy;

use LogicException;

final class TenantContextViolation extends LogicException
{
    public static function switchInsideActiveContext(): self
    {
        return new self('Cannot switch organization or actor inside an active tenant context.');
    }
}
