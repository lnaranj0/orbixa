<?php

namespace Modules\Platform\Tests\Security\Database;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Modules\Platform\Contracts\Events\ActorReference;
use Modules\Platform\Contracts\Events\DomainEvent;
use Modules\Platform\Contracts\Events\DomainEventRecorder;
use Modules\Platform\Contracts\Observability\CorrelationId;
use Modules\Platform\Contracts\Tenancy\OrganizationId;
use Modules\Platform\Infrastructure\Events\DeliverDomainEvent;
use Modules\Platform\Infrastructure\Events\OutboxRelay;
use Modules\Platform\Tests\Security\SecurityTestCase;
use Modules\Platform\Tests\Security\Support\TenantFixtures;
use RuntimeException;

/**
 * The relay publishes pending events of every organization exactly once per
 * row and backs off on failure without losing the event.
 */
final class OutboxRelayTest extends SecurityTestCase
{
    use TenantFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenants();
    }

    public function test_publishes_pending_events_of_all_organizations_with_the_v1_envelope(): void
    {
        Bus::fake([DeliverDomainEvent::class]);
        $correlationId = CorrelationId::start(null);

        $idA = $this->record($this->orgA);
        $idB = $this->record($this->orgB);

        $this->drain();

        foreach ([[$idA, $this->orgA], [$idB, $this->orgB]] as [$eventId, $organization]) {
            Bus::assertDispatched(DeliverDomainEvent::class, function (DeliverDomainEvent $job) use ($eventId, $organization, $correlationId) {
                return $job->envelope['event_id'] === $eventId
                    && $job->envelope['organization_id'] === $organization->value
                    && $job->envelope['correlation_id'] === $correlationId
                    && $job->envelope['event_name'] === 'platform.relay_probe.created'
                    && $job->envelope['actor'] === ['type' => 'system', 'id' => null];
            });
            $this->assertNotNull($this->outboxRow($organization, $eventId)->published_at);
        }
    }

    public function test_published_events_are_not_relayed_again(): void
    {
        Bus::fake([DeliverDomainEvent::class]);
        $id = $this->record($this->orgA);

        $this->drain();
        $this->drain();

        Bus::assertDispatchedTimes(
            fn (DeliverDomainEvent $job) => $job->envelope['event_id'] === $id,
            1,
        );
    }

    public function test_failed_delivery_is_retried_later_without_losing_the_event(): void
    {
        $id = $this->record($this->orgA);

        $failingBus = Mockery::mock(Dispatcher::class);
        $failingBus->shouldReceive('dispatch')->andThrow(new RuntimeException('queue down: secret-ish detail'));
        $relay = new OutboxRelay($this->app->make('db'), $failingBus, $this->app->make('config'));

        $relay->relay(1000);

        $row = $this->outboxRow($this->orgA, $id);
        $this->assertNull($row->published_at);
        $this->assertSame(1, (int) $row->attempts);
        $this->assertSame(RuntimeException::class, $row->last_error, 'Only the exception class may be stored.');
        $this->assertTrue((bool) $row->delayed, 'available_at must move into the future (backoff).');
    }

    private function drain(): void
    {
        for ($i = 0; $i < 20; $i++) {
            if ($this->app->make(OutboxRelay::class)->relay(1000)['published'] === 0) {
                return;
            }
        }
    }

    private function record(OrganizationId $organization): string
    {
        return $this->tenant->runFor($this->actor, $organization, fn () => $this->app->make(DomainEventRecorder::class)->record(
            new DomainEvent('platform.relay_probe.created', 1, 'relay_probe', (string) Str::uuid()),
            ActorReference::system(),
        ));
    }

    private function outboxRow(OrganizationId $organization, string $eventId): object
    {
        return $this->tenant->runFor($this->actor, $organization, fn () => DB::selectOne(
            'select published_at, attempts, last_error, available_at > now() as delayed
               from events.domain_outbox where event_id = ?',
            [$eventId]
        ));
    }
}
