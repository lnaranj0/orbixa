<?php

namespace Modules\GitHubIntegration\Infrastructure;

use Illuminate\Database\DatabaseManager;
use Modules\Platform\Contracts\Tenancy\IntegrationPrincipal;
use Modules\Platform\Contracts\Tenancy\IntegrationVerifier;
use Modules\Platform\Contracts\Tenancy\OrganizationId;

/**
 * 06 G-A/G-B: the only way to learn which organization a GitHub installation
 * belongs to without a tenant context, through the reviewed SECURITY
 * DEFINER function. Returns null for unknown, suspended or revoked
 * installations.
 */
final class InstallationRouter implements IntegrationVerifier
{
    public function __construct(private readonly DatabaseManager $db) {}

    public function organizationFor(int $installationExternalId): ?OrganizationId
    {
        if ($installationExternalId <= 0) {
            return null;
        }
        $row = $this->db->connection()->selectOne(
            'select integrations.organization_for_installation(?)::text as organization_id',
            [$installationExternalId]
        );
        $organization = $row === null ? null : ((array) $row)['organization_id'];

        return $organization === null ? null : OrganizationId::fromString((string) $organization);
    }

    public function isActiveFor(IntegrationPrincipal $principal, OrganizationId $organization): bool
    {
        if ($principal->provider !== 'github' || ! ctype_digit($principal->externalId)) {
            return false;
        }

        return $this->organizationFor((int) $principal->externalId)?->equals($organization) ?? false;
    }
}
