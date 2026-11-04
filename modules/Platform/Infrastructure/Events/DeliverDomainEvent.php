<?php

namespace Modules\Platform\Infrastructure\Events;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Platform\Contracts\Events\DomainEventPublished;
use Modules\Platform\Contracts\Observability\CorrelationId;

/**
 * Queued hand-off of one relayed outbox event to in-process listeners.
 */
final class DeliverDomainEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /**
     * @param  array{event_id: string, event_name: string, event_version: int, organization_id: string, aggregate_type: string, aggregate_id: string, actor: array{type: string, id: string|null}, correlation_id: string, causation_id: string|null, occurred_at: string, payload: array<string, mixed>}  $envelope
     */
    public function __construct(public readonly array $envelope) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    public function handle(Dispatcher $events): void
    {
        CorrelationId::start($this->envelope['correlation_id']);

        $events->dispatch(new DomainEventPublished($this->envelope));
    }
}
