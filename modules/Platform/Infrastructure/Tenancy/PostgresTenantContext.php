<?php

namespace Modules\Platform\Infrastructure\Tenancy;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\DatabaseManager;
use Modules\Platform\Contracts\Tenancy\MembershipVerifier;
use Modules\Platform\Contracts\Tenancy\OrganizationId;
use Modules\Platform\Contracts\Tenancy\TenantAccessDenied;
use Modules\Platform\Contracts\Tenancy\TenantContext;
use Modules\Platform\Contracts\Tenancy\TenantContextViolation;

/**
 * The only place allowed to set the PostgreSQL tenant GUC.
 *
 * The organization is accepted only after MembershipVerifier confirms the
 * actor's active membership. The GUC is set with is_local = true inside a
 * transaction, so it ends with COMMIT/ROLLBACK and never survives on a
 * reused or pooled connection. RLS is defense in depth, not the authority.
 */
final class PostgresTenantContext implements TenantContext
{
    private const SETTING = 'app.organization_id';

    private ?OrganizationId $current = null;

    private ?string $currentActor = null;

    public function __construct(
        private readonly MembershipVerifier $memberships,
        private readonly DatabaseManager $db,
    ) {}

    public function current(): ?OrganizationId
    {
        return $this->current;
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public function runFor(Authenticatable $actor, OrganizationId $organization, Closure $callback): mixed
    {
        if ($this->current !== null) {
            // Re-entry is only allowed for the same actor and organization the
            // membership check was made for.
            if (! $this->current->equals($organization) || $this->currentActor !== self::actorKey($actor)) {
                throw TenantContextViolation::switchInsideActiveContext();
            }

            return $callback();
        }

        if (! $this->memberships->hasActiveMembership($actor, $organization)) {
            throw TenantAccessDenied::forActor();
        }

        $connection = $this->db->connection();

        return $connection->transaction(function () use ($connection, $actor, $organization, $callback) {
            $connection->selectOne('select set_config(?, ?, true)', [self::SETTING, $organization->value]);
            $this->current = $organization;
            $this->currentActor = self::actorKey($actor);

            try {
                $result = $callback();
            } finally {
                $this->current = null;
                $this->currentActor = null;
            }

            // On success inside an outer transaction the savepoint is only
            // released, which keeps SET LOCAL values: clear it explicitly.
            // On failure the rollback already reverts the setting.
            $connection->selectOne('select set_config(?, ?, true)', [self::SETTING, '']);

            return $result;
        });
    }

    private static function actorKey(Authenticatable $actor): string
    {
        return $actor::class.'#'.$actor->getAuthIdentifier();
    }
}
