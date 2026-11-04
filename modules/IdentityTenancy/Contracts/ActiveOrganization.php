<?php

namespace Modules\IdentityTenancy\Contracts;

use Modules\Platform\Contracts\Tenancy\OrganizationId;

/**
 * The organization of the current HTTP request, resolved from the route
 * after verifying the user's active membership. It is only a selection:
 * every use case still opens TenantContext::runFor, which re-verifies.
 */
final readonly class ActiveOrganization
{
    public const SESSION_KEY = 'orbixa.active_organization_id';

    public function __construct(public OrganizationId $id) {}
}
