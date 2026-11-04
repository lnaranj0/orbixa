<?php

namespace Modules\Platform\Infrastructure\Tenancy;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use Modules\Platform\Contracts\Tenancy\AdmissionTicket;
use Modules\Platform\Contracts\Tenancy\AdmissionVerifier;
use Modules\Platform\Contracts\Tenancy\MembershipVerifier;
use Modules\Platform\Contracts\Tenancy\OrganizationId;
use Modules\Platform\Contracts\Tenancy\TenantAccessDenied;
use Modules\Platform\Contracts\Tenancy\TenantContext;
use Modules\Platform\Contracts\Tenancy\TenantContextViolation;

/**
 * The only place allowed to set the PostgreSQL tenant GUC.
 *
 * Authorization (membership or admission) is checked INSIDE the transaction
 * that then sets the GUC with is_local = true, so there is no window between
 * check and use, and the setting ends with COMMIT/ROLLBACK and never
 * survives on a reused or pooled connection. RLS is defense in depth, not
 * the authority.
 */
final class PostgresTenantContext implements TenantContext
{
    private const SETTING = 'app.organization_id';

    private ?OrganizationId $current = null;

    private ?string $currentActor = null;

    public function __construct(
        private readonly MembershipVerifier $memberships,
        private readonly AdmissionVerifier $admissions,
        private readonly DatabaseManager $db,
    ) {}

    public function current(): ?OrganizationId
    {
        return $this->current;
    }

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

        return $this->scoped($actor, $organization, function () use ($actor, $organization): void {
            if (! $this->memberships->hasActiveMembership($actor, $organization)) {
                throw TenantAccessDenied::forActor();
            }
        }, $callback, requireMembershipAfter: false);
    }

    public function runForNewOrganization(Authenticatable $actor, Closure $callback): mixed
    {
        $this->assertNoActiveContext();

        // Never taken from input: a caller cannot choose (or collide with) an id.
        $organization = OrganizationId::fromString((string) Str::uuid7());

        return $this->scoped($actor, $organization, fn () => null, fn () => $callback($organization), requireMembershipAfter: true);
    }

    public function runForAdmission(Authenticatable $actor, OrganizationId $organization, AdmissionTicket $ticket, Closure $callback): mixed
    {
        $this->assertNoActiveContext();

        return $this->scoped($actor, $organization, function () use ($actor, $organization, $ticket): void {
            if (! $this->admissions->canAdmit($actor, $organization, $ticket)) {
                throw TenantAccessDenied::forActor();
            }
        }, $callback, requireMembershipAfter: true);
    }

    /**
     * @template TResult
     *
     * @param  Closure(): void  $authorize  runs inside the transaction, before the GUC is set
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    private function scoped(Authenticatable $actor, OrganizationId $organization, Closure $authorize, Closure $callback, bool $requireMembershipAfter): mixed
    {
        $connection = $this->db->connection();

        return $connection->transaction(function () use ($connection, $actor, $organization, $authorize, $callback, $requireMembershipAfter) {
            $authorize();

            $this->set($connection, $organization->value);
            $this->current = $organization;
            $this->currentActor = self::actorKey($actor);

            try {
                $result = $callback();

                // Entry points that create a membership must leave the actor
                // as an active member, or the whole unit of work rolls back.
                if ($requireMembershipAfter && ! $this->memberships->hasActiveMembership($actor, $organization)) {
                    throw TenantContextViolation::membershipNotEstablished();
                }
            } finally {
                $this->current = null;
                $this->currentActor = null;
            }

            // On success inside an outer transaction the savepoint is only
            // released, which keeps SET LOCAL values: clear it explicitly.
            // On failure the rollback already reverts the setting.
            $this->set($connection, '');

            return $result;
        });
    }

    private function assertNoActiveContext(): void
    {
        if ($this->current !== null) {
            throw TenantContextViolation::switchInsideActiveContext();
        }
    }

    private function set(Connection $connection, string $value): void
    {
        $connection->selectOne('select set_config(?, ?, true)', [self::SETTING, $value]);
    }

    private static function actorKey(Authenticatable $actor): string
    {
        return $actor::class.'#'.$actor->getAuthIdentifier();
    }
}
