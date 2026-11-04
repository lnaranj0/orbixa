<?php

namespace Modules\Platform\Infrastructure\Tenancy;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Platform\Contracts\Tenancy\AdmissionTicket;
use Modules\Platform\Contracts\Tenancy\AdmissionVerifier;
use Modules\Platform\Contracts\Tenancy\OrganizationId;

/**
 * Fail-closed default until IdentityTenancy provides invitations.
 */
final class DenyAllAdmissionVerifier implements AdmissionVerifier
{
    public function canAdmit(Authenticatable $actor, OrganizationId $organization, AdmissionTicket $ticket): bool
    {
        return false;
    }
}
