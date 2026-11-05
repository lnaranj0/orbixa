<?php

namespace Modules\GitHubIntegration\Contracts;

use RuntimeException;

/** No such repository in the active organization (HTTP 404). */
final class GitHubRepositoryNotFound extends RuntimeException
{
    public static function make(): self
    {
        return new self('GitHub repository not found.');
    }
}
