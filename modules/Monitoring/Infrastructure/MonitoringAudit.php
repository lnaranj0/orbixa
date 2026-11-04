<?php

namespace Modules\Monitoring\Infrastructure;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\DatabaseManager;
use Modules\Platform\Contracts\Events\ActorReference;
use Modules\Platform\Contracts\Events\DomainEvent;
use Modules\Platform\Contracts\Events\DomainEventRecorder;

/**
 * M9: reading through a restricted or organization grant leaves
 * audit.sensitive_access.granted (operation monitoring.view), one per actor
 * and grant in a 10-minute window, as Portfolio does for projects.
 */
final class MonitoringAudit
{
    private const WINDOW = '10 minutes';

    public function __construct(
        private readonly DomainEventRecorder $events,
        private readonly DatabaseManager $db,
    ) {}

    /**
     * @param  list<string>  $grantIds
     */
    public function recordUse(Authenticatable $actor, string $viewerMembershipId, array $grantIds): void
    {
        $actorId = (string) $actor->getAuthIdentifier();
        foreach ($grantIds as $grantId) {
            $recent = $this->db->connection()->selectOne(
                "select 1 as found from events.domain_outbox
                  where event_name = 'audit.sensitive_access.granted' and aggregate_id = ?::uuid
                    and actor_type = 'human' and actor_id = ? and occurred_at > now() - interval '".self::WINDOW."'
                  limit 1",
                [$grantId, $actorId]
            );
            if ($recent !== null) {
                continue;
            }
            $this->events->record(
                new DomainEvent('audit.sensitive_access.granted', 1, 'monitoring_grant', $grantId, [
                    'reason' => 'monitoring_grant',
                    'operation' => 'monitoring.view',
                    'viewer_membership_id' => $viewerMembershipId,
                ]),
                ActorReference::human($actorId),
            );
        }
    }
}
