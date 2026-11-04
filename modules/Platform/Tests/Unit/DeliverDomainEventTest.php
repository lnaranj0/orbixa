<?php

namespace Modules\Platform\Tests\Unit;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\Platform\Contracts\Events\DomainEventPublished;
use Modules\Platform\Contracts\Observability\CorrelationId;
use Modules\Platform\Infrastructure\Events\DeliverDomainEvent;
use Tests\TestCase;

final class DeliverDomainEventTest extends TestCase
{
    public function test_job_fires_the_published_event_under_the_original_correlation_id(): void
    {
        Event::fake([DomainEventPublished::class]);
        $correlationId = (string) Str::uuid7();
        $envelope = [
            'event_id' => (string) Str::uuid7(),
            'event_name' => 'platform.relay_probe.created',
            'event_version' => 1,
            'organization_id' => (string) Str::uuid(),
            'aggregate_type' => 'relay_probe',
            'aggregate_id' => (string) Str::uuid(),
            'actor' => ['type' => 'system', 'id' => null],
            'correlation_id' => $correlationId,
            'causation_id' => null,
            'occurred_at' => '2026-11-03T17:00:00.000000Z',
            'payload' => [],
        ];

        (new DeliverDomainEvent($envelope))->handle($this->app->make('events'));

        Event::assertDispatched(DomainEventPublished::class, fn (DomainEventPublished $event) => $event->envelope === $envelope);
        $this->assertSame($correlationId, Context::get(CorrelationId::CONTEXT_KEY));
    }
}
