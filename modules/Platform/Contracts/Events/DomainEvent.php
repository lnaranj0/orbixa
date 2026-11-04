<?php

namespace Modules\Platform\Contracts\Events;

use InvalidArgumentException;

/**
 * Business event to publish through the transactional outbox (envelope v1,
 * docs/EVENTS.md). The organization, event id, correlation id and timestamps
 * are filled in by the recorder from the active context, never by the caller.
 * Payloads carry identifiers and minimal metadata: no credentials, no
 * sensitive text.
 */
final readonly class DomainEvent
{
    private const NAME_PATTERN = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/';

    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    /**
     * @param  array<array-key, mixed>  $payload  validated: every top-level key must be a string
     */
    public function __construct(
        public string $name,
        public int $version,
        public string $aggregateType,
        public string $aggregateId,
        public array $payload = [],
        public ?string $causationId = null,
    ) {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException('Event name must look like "module.aggregate.action".');
        }

        if ($version < 1) {
            throw new InvalidArgumentException('Event version must be a positive integer.');
        }

        if ($aggregateType === '' || preg_match(self::UUID_PATTERN, $aggregateId) !== 1) {
            throw new InvalidArgumentException('Aggregate type and a lowercase UUID aggregate id are required.');
        }

        foreach (array_keys($payload) as $key) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('Event payload must be a key/value object with string keys.');
            }
        }

        if ($causationId !== null && preg_match(self::UUID_PATTERN, $causationId) !== 1) {
            throw new InvalidArgumentException('Causation id must be a lowercase UUID.');
        }
    }
}
