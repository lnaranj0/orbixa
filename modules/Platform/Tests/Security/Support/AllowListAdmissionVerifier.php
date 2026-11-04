<?php

namespace Modules\Platform\Tests\Security\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Platform\Contracts\Tenancy\AdmissionTicket;
use Modules\Platform\Contracts\Tenancy\AdmissionVerifier;
use Modules\Platform\Contracts\Tenancy\OrganizationId;

/**
 * Test double for invitation-based admission: admits only the configured
 * (organization, secret) pairs.
 */
final class AllowListAdmissionVerifier implements AdmissionVerifier
{
    /** @var array<string, string> */
    private array $tickets = [];

    public function allow(OrganizationId $organization, string $secret): self
    {
        $this->tickets[$organization->value] = $secret;

        return $this;
    }

    public function canAdmit(Authenticatable $actor, OrganizationId $organization, AdmissionTicket $ticket): bool
    {
        return isset($this->tickets[$organization->value])
            && hash_equals($this->tickets[$organization->value], $ticket->reveal());
    }
}
