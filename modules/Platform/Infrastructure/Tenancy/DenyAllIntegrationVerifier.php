<?php

namespace Modules\Platform\Infrastructure\Tenancy;

use Modules\Platform\Contracts\Tenancy\IntegrationPrincipal;
use Modules\Platform\Contracts\Tenancy\IntegrationVerifier;
use Modules\Platform\Contracts\Tenancy\OrganizationId;

/** Default until an integration module binds a real verifier: nothing is ever active. */
final class DenyAllIntegrationVerifier implements IntegrationVerifier
{
    public function isActiveFor(IntegrationPrincipal $principal, OrganizationId $organization): bool
    {
        return false;
    }
}
