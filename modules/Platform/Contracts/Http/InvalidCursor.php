<?php

namespace Modules\Platform\Contracts\Http;

use RuntimeException;

/** Malformed, edited or foreign cursor (HTTP 422). */
final class InvalidCursor extends RuntimeException
{
    public static function make(): self
    {
        return new self('The cursor is not valid.');
    }
}
