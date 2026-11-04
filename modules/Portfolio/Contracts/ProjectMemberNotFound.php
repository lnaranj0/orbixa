<?php

namespace Modules\Portfolio\Contracts;

use RuntimeException;

/** No active project membership to remove (HTTP 404). */
final class ProjectMemberNotFound extends RuntimeException
{
    public static function make(): self
    {
        return new self('Project member not found.');
    }
}
