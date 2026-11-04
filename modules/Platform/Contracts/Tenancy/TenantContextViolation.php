<?php

namespace Modules\Platform\Contracts\Tenancy;

use LogicException;

final class TenantContextViolation extends LogicException
{
    public static function switchInsideActiveContext(): self
    {
        return new self('Cannot switch organization or actor inside an active tenant context.');
    }

    public static function membershipNotEstablished(): self
    {
        return new self('The operation finished without an active membership for the actor; it was rolled back.');
    }
}
