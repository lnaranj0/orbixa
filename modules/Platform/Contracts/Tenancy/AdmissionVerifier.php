<?php

namespace Modules\Platform\Contracts\Tenancy;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Decides whether an actor without membership may join an organization with
 * the given ticket (e.g. a valid invitation for the actor's verified email).
 * Called inside the transaction that will create the membership; the real
 * implementation arrives with the IdentityTenancy invitations.
 */
interface AdmissionVerifier
{
    public function canAdmit(Authenticatable $actor, OrganizationId $organization, AdmissionTicket $ticket): bool;
}
