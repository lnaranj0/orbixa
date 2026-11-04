<?php

namespace Modules\Platform\Contracts\Events;

use InvalidArgumentException;

/**
 * Who caused a domain event. Carries identifiers only, never names or emails.
 */
final readonly class ActorReference
{
    private const TYPES = ['human', 'agent', 'service', 'system'];

    private function __construct(public string $type, public ?string $id) {}

    public static function human(string $id): self
    {
        return self::of('human', $id);
    }

    public static function system(): self
    {
        return new self('system', null);
    }

    public static function of(string $type, ?string $id): self
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('Unknown actor type.');
        }

        if ($type !== 'system' && ($id === null || $id === '')) {
            throw new InvalidArgumentException('Actor id is required for non-system actors.');
        }

        return new self($type, $id);
    }
}
