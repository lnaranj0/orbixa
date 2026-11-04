<?php

namespace Modules\IdentityTenancy\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Organizations where the actor has an active membership (organization
 * selector). Works without a tenant context, through the narrow
 * identity.organizations_for_user() function.
 */
interface MembershipDirectory
{
    /**
     * @return list<OrganizationSummary>
     */
    public function organizationsFor(Authenticatable $actor): array;
}
