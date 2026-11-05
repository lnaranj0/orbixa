<?php

namespace Modules\Platform\Contracts\Tenancy;

use InvalidArgumentException;

/**
 * A non-human actor acting for one organization through an external
 * integration (06 G-B), e.g. a GitHub App installation. It is never an
 * Authenticatable user and holds no organization role.
 */
final readonly class IntegrationPrincipal
{
    private function __construct(public string $provider, public string $externalId) {}

    public static function github(int $installationExternalId): self
    {
        if ($installationExternalId <= 0) {
            throw new InvalidArgumentException('Installation id must be positive.');
        }

        return new self('github', (string) $installationExternalId);
    }

    /** Actor id recorded in events and audit, e.g. "integration:github:123". */
    public function actorId(): string
    {
        return "integration:{$this->provider}:{$this->externalId}";
    }
}
