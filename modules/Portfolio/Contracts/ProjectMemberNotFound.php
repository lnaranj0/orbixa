<?php

namespace Modules\Portfolio\Contracts;

use RuntimeException;

/** Unknown, inactive or foreign membership, or no active project membership to remove (HTTP 404). */
final class ProjectMemberNotFound extends RuntimeException
{
    public static function make(): self
    {
        return new self('Project member not found.');
    }
}
