<?php

namespace Modules\Platform\Contracts\Validation;

use InvalidArgumentException;

/**
 * A use case rejected its input (HTTP 422 with this message). Only for
 * domain validation: other InvalidArgumentExceptions stay 500, so
 * programming errors are not disguised as client errors.
 */
final class InputRejected extends InvalidArgumentException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
