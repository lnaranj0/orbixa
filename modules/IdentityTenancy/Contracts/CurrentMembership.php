<?php

namespace Modules\IdentityTenancy\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The actor's active membership id in the ACTIVE tenant context (null
 * outside a context or without an active membership).
 */
interface CurrentMembership
{
    public function idFor(Authenticatable $actor): ?string;
}
