<?php

namespace Modules\Platform\Contracts\Events;

/**
 * Laravel event fired on the worker for every relayed outbox event
 * (envelope v1, docs/EVENTS.md). Delivery is at-least-once: listeners must
 * deduplicate by event id (events.consumer_receipts) and re-check
 * authorization; the event itself never grants permissions.
 */
final readonly class DomainEventPublished
{
    /**
     * @param  array{event_id: string, event_name: string, event_version: int, organization_id: string, aggregate_type: string, aggregate_id: string, actor: array{type: string, id: string|null}, correlation_id: string, causation_id: string|null, occurred_at: string, payload: array<string, mixed>}  $envelope
     */
    public function __construct(public array $envelope) {}

    public function name(): string
    {
        return $this->envelope['event_name'];
    }

    public function eventId(): string
    {
        return $this->envelope['event_id'];
    }

    public function organizationId(): string
    {
        return $this->envelope['organization_id'];
    }
}
