<?php

namespace Modules\GitHubIntegration\Contracts;

use RuntimeException;

/** Member of the organization without integrations.github.manage (HTTP 403). */
final class GitHubOperationDenied extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
