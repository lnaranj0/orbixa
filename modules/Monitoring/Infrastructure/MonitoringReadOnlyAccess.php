<?php

namespace Modules\Monitoring\Infrastructure;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Log;
use Modules\Monitoring\Application\MonitoringVisibility;
use Modules\Platform\Contracts\Events\ActorReference;
use Modules\Platform\Contracts\Events\DomainEvent;
use Modules\Platform\Contracts\Events\DomainEventRecorder;
use Modules\Platform\Contracts\Tenancy\TenantContext;
use Modules\Work\Contracts\Monitoring\MonitoredWorkReader;
use Modules\Work\Contracts\ReadOnlyItemAccess;

/**
 * 04 M2: a viewer whose grants cover an item gets 403 (not 404) when trying
 * to change it through Work, and the attempt is recorded as
 * monitoring.viewer.access_denied (ids only; once per actor and item in a
 * 10-minute window).
 */
final class MonitoringReadOnlyAccess implements ReadOnlyItemAccess
{
    private const WINDOW = '10 minutes';

    public function __construct(
        private readonly MonitoringVisibility $visibility,
        private readonly MonitoredWorkReader $work,
        private readonly DomainEventRecorder $events,
        private readonly DatabaseManager $db,
        private readonly TenantContext $tenant,
    ) {}

    public function refuseWrite(Authenticatable $actor, string $itemId): bool
    {
        $scope = $this->visibility->scopeFor($actor);
        if ($scope->isEmpty() || ! $this->work->covers($scope->selector, $itemId)) {
            return false;
        }

        // The refused write rolls the tenant transaction back, which would
        // also discard the audit row: record it after the rollback, in a new
        // tenant transaction (membership re-verified).
        $organization = $this->tenant->current();
        $viewerMembershipId = $scope->viewerMembershipId;
        $record = function () use ($actor, $itemId, $viewerMembershipId, $organization): void {
            try {
                $organization === null
                    ? $this->record($actor, $itemId, $viewerMembershipId)
                    : $this->tenant->runFor($actor, $organization, fn () => $this->record($actor, $itemId, $viewerMembershipId));
            } catch (\Throwable $exception) {
                // Never let the audit write mask the 403; leave a trace instead.
                Log::warning('monitoring.viewer.access_denied could not be recorded', ['exception' => $exception::class]);
            }
        };
        // Top-level request transaction: write after its rollback. Nested
        // callers (outer transaction still open) are not expected on write
        // paths; there the row joins the outer transaction.
        if ($this->db->connection()->transactionLevel() === 1) {
            $this->db->connection()->afterRollBack($record);
        } else {
            $this->record($actor, $itemId, $viewerMembershipId);
        }

        return true;
    }

    private function record(Authenticatable $actor, string $itemId, string $viewerMembershipId): void
    {
        $actorId = (string) $actor->getAuthIdentifier();
        $recent = $this->db->connection()->selectOne(
            "select 1 as found from events.domain_outbox
              where event_name = 'monitoring.viewer.access_denied' and aggregate_id = ?::uuid
                and actor_type = 'human' and actor_id = ? and occurred_at > now() - interval '".self::WINDOW."'
              limit 1",
            [$itemId, $actorId]
        );
        if ($recent !== null) {
            return;
        }

        $this->events->record(
            new DomainEvent('monitoring.viewer.access_denied', 1, 'work_item', $itemId, [
                'viewer_membership_id' => $viewerMembershipId,
                'operation' => 'write',
            ]),
            ActorReference::human($actorId),
        );
    }
}
