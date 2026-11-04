<?php

namespace Modules\Platform\Contracts\Tenancy;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Decides whether an authenticated identity holds an active, authorized
 * membership in an organization. The tenant context is only established
 * after this returns true; the PostgreSQL GUC alone is never trusted.
 *
 * 00-platform-foundation ships a deny-all implementation; the real one
 * arrives with 01-identity-tenancy.
 *
 * It runs BEFORE any tenant context exists, so it must not read tables that
 * are filtered by organization RLS (they would always look empty). The
 * membership lookup needs a dedicated, narrowly scoped read path, to be
 * designed and reviewed in 01-identity-tenancy.
 */
interface MembershipVerifier
{
    public function hasActiveMembership(Authenticatable $actor, OrganizationId $organization): bool;
}
