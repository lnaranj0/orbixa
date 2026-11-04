<?php

namespace Modules\Portfolio\Infrastructure;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\DatabaseManager;
use Modules\Platform\Contracts\Events\ActorReference;
use Modules\Platform\Contracts\Events\DomainEvent;
use Modules\Platform\Contracts\Events\DomainEventRecorder;

/**
 * D3: access to a restricted project through the organization role (not as
 * a direct member) leaves audit.sensitive_access.granted. One event per
 * actor, project and 10-minute window, so repeated or forged GETs cannot
 * flood the audit trail.
 */
final class SensitiveAccessAudit
{
    private const WINDOW = '10 minutes';

    public function __construct(
        private readonly DomainEventRecorder $events,
        private readonly DatabaseManager $db,
    ) {}

    public function record(Authenticatable $actor, string $membershipId, string $projectId, string $operation): void
    {
        $actorId = (string) $actor->getAuthIdentifier();

        $recent = $this->db->connection()->selectOne(
            "select 1 as found from events.domain_outbox
              where event_name = 'audit.sensitive_access.granted' and aggregate_id = ?::uuid
                and actor_type = 'human' and actor_id = ? and payload->>'operation' = ?
                and occurred_at > now() - interval '".self::WINDOW."'
              limit 1",
            [$projectId, $actorId, $operation]
        );
        if ($recent !== null) {
            return;
        }

        $this->events->record(
            new DomainEvent('audit.sensitive_access.granted', 1, 'project', $projectId, [
                'reason' => 'organization_role',
                'operation' => $operation,
                'viewer_membership_id' => $membershipId,
            ]),
            ActorReference::human($actorId),
        );
    }
}
