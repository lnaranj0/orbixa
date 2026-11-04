<?php

namespace Modules\Planning\Contracts;

use RuntimeException;

/** Unknown, foreign or not visible project or cycle (HTTP 404). */
final class CycleNotFound extends RuntimeException
{
    public static function make(): self
    {
        return new self('Not found.');
    }
}
