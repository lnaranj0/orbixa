<?php

namespace Modules\IdentityTenancy\Contracts;

/**
 * A queued job that must run inside an organization on behalf of a user.
 * It carries a TenantScope (identifiers only); on execution the membership
 * is re-verified (TenantContext::runFor). A revoked membership fails the
 * job closed, without retries.
 */
interface TenantAwareJob
{
    public function tenantScope(): TenantScope;
}
