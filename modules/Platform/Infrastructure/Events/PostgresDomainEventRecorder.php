<?php

namespace Modules\Platform\Infrastructure\Events;

use Illuminate\Database\DatabaseManager;
use Modules\Platform\Contracts\Events\ActorReference;
use Modules\Platform\Contracts\Events\DomainEvent;
use Modules\Platform\Contracts\Events\DomainEventRecorder;
use Modules\Platform\Contracts\Observability\CorrelationId;
use Modules\Platform\Contracts\Tenancy\TenantContext;
use Modules\Platform\Contracts\Tenancy\TenantContextRequired;

/**
 * Inserts into events.domain_outbox inside the caller's tenant transaction.
 * The organization comes from the database tenant context
 * (platform.current_organization_id()), so a caller can never publish an
 * event for another organization; RLS WITH CHECK enforces it again.
 */
final class PostgresDomainEventRecorder implements DomainEventRecorder
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly DatabaseManager $db,
    ) {}

    public function record(DomainEvent $event, ActorReference $actor): string
    {
        $connection = $this->db->connection();

        if ($this->tenant->current() === null || $connection->transactionLevel() === 0) {
            throw TenantContextRequired::forOperation('Recording a domain event');
        }

        return $connection->selectOne(
            'insert into events.domain_outbox
                (organization_id, aggregate_type, aggregate_id, event_name, event_version, payload,
                 actor_type, actor_id, correlation_id, causation_id)
             values (platform.current_organization_id(), ?, ?, ?, ?, ?::jsonb, ?, ?, ?, ?)
             returning event_id',
            [
                $event->aggregateType,
                $event->aggregateId,
                $event->name,
                $event->version,
                // (object): an empty payload must be stored as {} (JSON object), not [].
                json_encode((object) $event->payload, JSON_THROW_ON_ERROR),
                $actor->type,
                $actor->id,
                CorrelationId::current(),
                $event->causationId,
            ]
        )->event_id;
    }
}
