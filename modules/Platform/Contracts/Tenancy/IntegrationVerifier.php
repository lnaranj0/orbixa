<?php

namespace Modules\Platform\Contracts\Tenancy;

/**
 * Decides whether an integration principal is currently active for an
 * organization (06 G-B). Like MembershipVerifier it runs BEFORE the tenant
 * context exists, through a narrow reviewed read path. Platform ships a
 * deny-all default; the GitHub integration provides the real one.
 */
interface IntegrationVerifier
{
    public function isActiveFor(IntegrationPrincipal $principal, OrganizationId $organization): bool;
}
