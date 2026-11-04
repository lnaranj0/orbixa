<?php

namespace Modules\Platform\Contracts\Idempotency;

use RuntimeException;

final class IdempotencyConflict extends RuntimeException
{
    public static function differentRequest(): self
    {
        return new self('Idempotency key was already used with a different request.');
    }
}
