<?php

namespace Modules\Platform\Contracts\Tenancy;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Public contract for running work inside one organization. Other modules
 * depend on this interface, never on the PostgreSQL implementation.
 */
interface TenantContext
{
    public function current(): ?OrganizationId;

    /**
     * Verifies the actor's active membership, then runs the callback inside a
     * transaction scoped to the organization (fail-closed RLS context).
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     *
     * @throws TenantAccessDenied when the actor has no active membership
     * @throws TenantContextViolation when switching organization or actor inside an active context
     */
    public function runFor(Authenticatable $actor, OrganizationId $organization, Closure $callback): mixed;
}
