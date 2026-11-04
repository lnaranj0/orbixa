<?php

namespace Modules\Access\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Deny-by-default permission check for the ACTIVE tenant context. Outside a
 * context, or without an active membership, every check is false.
 */
interface Authorizer
{
    public function can(Authenticatable $actor, Permission $permission): bool;
}
