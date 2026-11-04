<?php

namespace Modules\Platform\Contracts\Tenancy;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Public contract for running work inside one organization. Other modules
 * depend on this interface, never on the PostgreSQL implementation.
 *
 * Every entry point opens a transaction, verifies authorization INSIDE it
 * (no window between check and use) and only then sets the tenant context.
 */
interface TenantContext
{
    public function current(): ?OrganizationId;

    /**
     * Runs the callback for an existing membership.
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

    /**
     * Creates a new organization: the id is generated server-side and passed
     * to the callback, which must create the organization and the actor's
     * active owner membership. If it does not, everything is rolled back.
     *
     * @template TResult
     *
     * @param  Closure(OrganizationId): TResult  $callback
     * @return TResult
     *
     * @throws TenantContextViolation when no active membership exists afterwards
     */
    public function runForNewOrganization(Authenticatable $actor, Closure $callback): mixed;

    /**
     * Joins an existing organization without a prior membership (invitation
     * acceptance). The AdmissionVerifier decides inside the transaction; the
     * callback must create the actor's active membership or everything is
     * rolled back.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     *
     * @throws TenantAccessDenied when the ticket does not admit the actor
     * @throws TenantContextViolation when no active membership exists afterwards
     */
    public function runForAdmission(Authenticatable $actor, OrganizationId $organization, AdmissionTicket $ticket, Closure $callback): mixed;
}
