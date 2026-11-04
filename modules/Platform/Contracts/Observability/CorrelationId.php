<?php

namespace Modules\Platform\Contracts\Observability;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

/**
 * Correlation id shared by HTTP requests, logs, queued jobs and outbox
 * events. Stored in Laravel Context, which adds it to every log record and
 * propagates it to queued jobs automatically.
 */
final class CorrelationId
{
    public const CONTEXT_KEY = 'correlation_id';

    public const HEADER = 'X-Correlation-Id';

    private const PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    /**
     * Returns the current correlation id, creating one when none (or an
     * invalid one) is set.
     */
    public static function current(): string
    {
        $current = Context::get(self::CONTEXT_KEY);

        if (is_string($current) && preg_match(self::PATTERN, $current) === 1) {
            return $current;
        }

        return self::start(null);
    }

    /**
     * Starts a correlation scope, accepting an inbound id only when it is a
     * well-formed UUID; anything else is replaced, never echoed back.
     */
    public static function start(?string $inbound): string
    {
        $candidate = strtolower(trim((string) $inbound));
        $id = preg_match(self::PATTERN, $candidate) === 1 ? $candidate : (string) Str::uuid7();

        Context::add(self::CONTEXT_KEY, $id);

        return $id;
    }
}
