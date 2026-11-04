<?php

namespace Modules\Work\Infrastructure;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Log;
use Modules\Platform\Contracts\Events\ActorReference;
use Modules\Platform\Contracts\Events\DomainEvent;
use Modules\Platform\Contracts\Events\DomainEventRecorder;
use Modules\Platform\Contracts\Tenancy\TenantContext;

/**
 * 05 P10: a transition that the workflow does not allow is answered 422 and
 * recorded as work.item.transition_rejected (ids only). The 422 rolls the
 * request transaction back, so the row is written right after the rollback
 * in a fresh tenant transaction, once per actor and item in a 10-minute
 * window, and a failure to record never masks the 422 (pattern of 04 M-3).
 */
final class RejectedTransitionAudit
{
    private const WINDOW = '10 minutes';

    public function __construct(
        private readonly DomainEventRecorder $events,
        private readonly DatabaseManager $db,
        private readonly TenantContext $tenant,
    ) {}

    public function record(Authenticatable $actor, string $itemId, string $projectId, string $fromStatusId, string $toStatusId): void
    {
        $organization = $this->tenant->current();
        $write = function () use ($actor, $itemId, $projectId, $fromStatusId, $toStatusId, $organization): void {
            try {
                $organization === null
                    ? $this->write($actor, $itemId, $projectId, $fromStatusId, $toStatusId)
                    : $this->tenant->runFor($actor, $organization, fn () => $this->write($actor, $itemId, $projectId, $fromStatusId, $toStatusId));
            } catch (\Throwable $exception) {
                Log::warning('work.item.transition_rejected could not be recorded', ['exception' => $exception::class]);
            }
        };

        if ($this->db->connection()->transactionLevel() === 1) {
            $this->db->connection()->afterRollBack($write);
        } else {
            $this->write($actor, $itemId, $projectId, $fromStatusId, $toStatusId);
        }
    }

    private function write(Authenticatable $actor, string $itemId, string $projectId, string $fromStatusId, string $toStatusId): void
    {
        $actorId = (string) $actor->getAuthIdentifier();
        $recent = $this->db->connection()->selectOne(
            "select 1 as found from events.domain_outbox
              where event_name = 'work.item.transition_rejected' and aggregate_id = ?::uuid
                and actor_type = 'human' and actor_id = ? and payload->>'to_status_id' = ?
                and occurred_at > now() - interval '".self::WINDOW."'
              limit 1",
            [$itemId, $actorId, $toStatusId]
        );
        if ($recent !== null) {
            return;
        }

        $this->events->record(
            new DomainEvent('work.item.transition_rejected', 1, 'work_item', $itemId, [
                'project_id' => $projectId, 'from_status_id' => $fromStatusId, 'to_status_id' => $toStatusId,
            ]),
            ActorReference::human($actorId),
        );
    }
}
