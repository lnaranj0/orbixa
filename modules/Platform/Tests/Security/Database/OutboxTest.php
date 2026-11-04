<?php

namespace Modules\Platform\Tests\Security\Database;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Platform\Contracts\Events\ActorReference;
use Modules\Platform\Contracts\Events\DomainEvent;
use Modules\Platform\Contracts\Events\DomainEventRecorder;
use Modules\Platform\Contracts\Observability\CorrelationId;
use Modules\Platform\Contracts\Tenancy\TenantContextRequired;
use Modules\Platform\Tests\Security\SecurityTestCase;
use Modules\Platform\Tests\Security\Support\TenantFixtures;
use RuntimeException;

/**
 * Spec "Outbox transaccional": the event commits or rolls back together
 * with the business mutation, always for the active organization.
 */
final class OutboxTest extends SecurityTestCase
{
    use TenantFixtures;

    private DomainEventRecorder $recorder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenants();
        $this->recorder = $this->app->make(DomainEventRecorder::class);
    }

    public function test_event_commits_with_the_business_mutation(): void
    {
        $correlationId = CorrelationId::start(null);

        [$probeId, $eventId] = $this->tenant->runFor($this->actor, $this->orgA, function () {
            $probeId = $this->insertProbe('created');

            return [$probeId, $this->recorder->record($this->event($probeId), ActorReference::human('1'))];
        });

        $event = $this->tenant->runFor($this->actor, $this->orgA, fn () => DB::selectOne(
            'select organization_id::text as organization_id, aggregate_id::text as aggregate_id,
                    correlation_id::text as correlation_id, event_name, published_at
               from events.domain_outbox where event_id = ?',
            [$eventId]
        ));

        $this->assertSame($this->orgA->value, $event->organization_id);
        $this->assertSame($probeId, $event->aggregate_id);
        $this->assertSame($correlationId, $event->correlation_id);
        $this->assertSame('platform.tenant_probe.created', $event->event_name);
        $this->assertNull($event->published_at);
    }

    public function test_event_is_discarded_when_the_mutation_rolls_back(): void
    {
        $aggregateId = (string) Str::uuid();

        try {
            $this->tenant->runFor($this->actor, $this->orgA, function () use ($aggregateId) {
                $this->recorder->record($this->event($aggregateId), ActorReference::system());

                throw new RuntimeException('business failure');
            });
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(0, $this->tenant->runFor($this->actor, $this->orgA, fn () => (int) DB::selectOne(
            'select count(*) as total from events.domain_outbox where aggregate_id = ?',
            [$aggregateId]
        )->total));
    }

    public function test_recording_requires_an_active_tenant_context(): void
    {
        $this->expectException(TenantContextRequired::class);

        DB::transaction(fn () => $this->recorder->record($this->event((string) Str::uuid()), ActorReference::system()));
    }

    public function test_events_are_isolated_between_organizations(): void
    {
        $aggregateId = (string) Str::uuid();
        $this->tenant->runFor($this->actor, $this->orgA, fn () => $this->recorder->record($this->event($aggregateId), ActorReference::system()));

        $visibleToB = $this->tenant->runFor($this->actor, $this->orgB, fn () => (int) DB::selectOne(
            'select count(*) as total from events.domain_outbox where aggregate_id = ?',
            [$aggregateId]
        )->total);

        $this->assertSame(0, $visibleToB);
    }

    public function test_cannot_insert_an_event_for_another_organization(): void
    {
        $this->tenant->runFor($this->actor, $this->orgA, function () {
            $this->assertSqlState('42501', fn (Connection $connection) => $connection->insert(
                "insert into events.domain_outbox
                    (organization_id, aggregate_type, aggregate_id, event_name, event_version, actor_type, correlation_id)
                 values (?, 'probe', ?, 'platform.probe.forged', 1, 'system', ?)",
                [$this->orgB->value, (string) Str::uuid(), (string) Str::uuid()]
            ));
        });
    }

    public function test_runtime_role_cannot_rewrite_or_delete_outbox_history(): void
    {
        $this->tenant->runFor($this->actor, $this->orgA, function () {
            $this->assertSqlState('42501', fn (Connection $connection) => $connection->update(
                "update events.domain_outbox set event_name = 'platform.probe.rewritten'"
            ));
            $this->assertSqlState('42501', fn (Connection $connection) => $connection->delete(
                'delete from events.domain_outbox'
            ));
        });
    }

    public function test_correlation_id_reaches_the_queue_payload_context(): void
    {
        $correlationId = CorrelationId::start(null);

        // Same round trip the queue performs: dehydrate on dispatch, hydrate on the worker.
        $payload = Context::dehydrate();
        Context::flush();
        Context::hydrate($payload);

        $this->assertSame($correlationId, Context::get(CorrelationId::CONTEXT_KEY));
    }

    private function event(string $aggregateId): DomainEvent
    {
        return new DomainEvent('platform.tenant_probe.created', 1, 'tenant_probe', $aggregateId, ['probe_id' => $aggregateId]);
    }

    private function insertProbe(string $label): string
    {
        return DB::selectOne(
            'insert into platform.tenant_probe (organization_id, label)
             values (platform.current_organization_id(), ?) returning id::text as id',
            [$label]
        )->id;
    }
}
