<?php

namespace Modules\Portfolio\Contracts;

use RuntimeException;

/** Unknown, foreign or not visible project, indistinguishable (HTTP 404). */
final class ProjectNotFound extends RuntimeException
{
    public static function make(): self
    {
        return new self('Project not found.');
    }
}
