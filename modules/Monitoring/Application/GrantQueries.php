<?php

namespace Modules\Monitoring\Application;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Access\Contracts\Permission;
use Modules\Monitoring\Contracts\GrantNotFound;
use Modules\Monitoring\Infrastructure\GrantStore;

/**
 * Grant inventory (M5, M10): only monitoring.grants.view (owner/admin).
 * A viewer never lists grants, not even its own.
 *
 * @phpstan-import-type GrantRow from GrantStore
 */
final class GrantQueries
{
    public function __construct(
        private readonly GrantGuard $guard,
        private readonly GrantStore $grants,
    ) {}

    /**
     * @param  array{0: string, 1: string}|null  $after
     * @return list<GrantRow>
     */
    public function page(Authenticatable $actor, ?string $viewerMembershipId, bool $activeOnly, ?array $after, int $limit): array
    {
        $this->guard->require($actor, Permission::MonitoringGrantsView);

        return $this->grants->page($viewerMembershipId, $activeOnly, $after, $limit);
    }

    /**
     * @return GrantRow
     */
    public function find(Authenticatable $actor, string $grantId): array
    {
        $this->guard->require($actor, Permission::MonitoringGrantsView);

        return $this->grants->find($grantId) ?? throw GrantNotFound::make();
    }
}
