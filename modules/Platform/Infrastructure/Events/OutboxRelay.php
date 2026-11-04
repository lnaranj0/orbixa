<?php

namespace Modules\Platform\Infrastructure\Events;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Throwable;

/**
 * Publishes pending outbox events (option A). Runs on the dedicated relay
 * connection, which can only see and mark pending rows of
 * events.domain_outbox. Rows are locked with FOR UPDATE SKIP LOCKED, so
 * concurrent relays never publish the same row twice in one pass; a crash
 * between queue push and commit can repeat a delivery (at-least-once), which
 * consumers absorb through events.consumer_receipts.
 */
final class OutboxRelay
{
    private const MAX_BACKOFF_SECONDS = 3600;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Dispatcher $bus,
        private readonly Repository $config,
    ) {}

    /**
     * @return array{published: int, failed: int}
     */
    public function relay(int $batchSize = 100): array
    {
        $connection = $this->db->connection((string) $this->config->get('database.relay_connection'));

        return $connection->transaction(function () use ($connection, $batchSize): array {
            $events = $connection->select(
                'select event_id::text as event_id, event_name, event_version, organization_id::text as organization_id,
                        aggregate_type, aggregate_id::text as aggregate_id, actor_type, actor_id,
                        correlation_id::text as correlation_id, causation_id::text as causation_id,
                        to_char(occurred_at at time zone \'UTC\', \'YYYY-MM-DD"T"HH24:MI:SS.US"Z"\') as occurred_at,
                        payload::text as payload, attempts
                   from events.domain_outbox
                  where published_at is null and available_at <= now()
                  order by available_at, event_id
                  limit ?
                    for update skip locked',
                [max(1, min($batchSize, 1000))]
            );

            $published = 0;
            $failed = 0;

            foreach ($events as $event) {
                try {
                    // Savepoint per event: a failure rolls back only this event,
                    // leaving the batch transaction usable to record the retry.
                    $connection->transaction(function () use ($connection, $event): void {
                        $this->bus->dispatch(new DeliverDomainEvent([
                            'event_id' => $event->event_id,
                            'event_name' => $event->event_name,
                            'event_version' => (int) $event->event_version,
                            'organization_id' => $event->organization_id,
                            'aggregate_type' => $event->aggregate_type,
                            'aggregate_id' => $event->aggregate_id,
                            'actor' => ['type' => $event->actor_type, 'id' => $event->actor_id],
                            'correlation_id' => $event->correlation_id,
                            'causation_id' => $event->causation_id,
                            'occurred_at' => $event->occurred_at,
                            'payload' => json_decode($event->payload, true, flags: JSON_THROW_ON_ERROR),
                        ]));

                        $connection->update(
                            'update events.domain_outbox set published_at = now(), last_error = null where event_id = ?',
                            [$event->event_id]
                        );
                    });
                    $published++;
                } catch (Throwable $exception) {
                    // Only the exception class is stored: messages may carry data.
                    $connection->update(
                        'update events.domain_outbox
                            set attempts = attempts + 1,
                                last_error = ?,
                                available_at = now() + make_interval(secs => least(?, power(2, attempts + 1)))
                          where event_id = ?',
                        [$exception::class, self::MAX_BACKOFF_SECONDS, $event->event_id]
                    );
                    $failed++;
                }
            }

            return ['published' => $published, 'failed' => $failed];
        });
    }
}
