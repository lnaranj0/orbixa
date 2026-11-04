<?php

namespace Modules\IdentityTenancy\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Platform\Contracts\Tenancy\OrganizationId;

/**
 * The organization and user a queued job acts for: identifiers only, so the
 * job payload never carries names, emails or models (docs/EVENTS.md).
 */
final readonly class TenantScope
{
    private function __construct(
        public string $organizationId,
        public string $actorUserId,
    ) {}

    public static function of(OrganizationId $organization, Authenticatable $actor): self
    {
        return new self($organization->value, (string) $actor->getAuthIdentifier());
    }
}
