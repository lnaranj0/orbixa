<?php

namespace Modules\Portfolio\Contracts;

use RuntimeException;

/** The team has no active link to the project (HTTP 404). */
final class ProjectLinkNotFound extends RuntimeException
{
    public static function make(): self
    {
        return new self('Project team link not found.');
    }
}
