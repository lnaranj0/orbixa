<?php

namespace Modules\GitHubIntegration\Application\Ports;

/**
 * Raised for any GitHub failure. Carries the operation and the HTTP status
 * only: never the body, headers or the transport exception (G10).
 */
final class GitHubApiFailure extends \RuntimeException
{
    private function __construct(string $message, public readonly string $operation, public readonly ?int $status)
    {
        parent::__construct($message);
    }

    public static function status(string $operation, int $status): self
    {
        return new self("GitHub call failed ({$operation}, HTTP {$status}).", $operation, $status);
    }

    public static function unreachable(string $operation): self
    {
        return new self("GitHub is unreachable ({$operation}).", $operation, null);
    }

    public static function unexpected(string $operation): self
    {
        return new self("GitHub returned an unexpected response ({$operation}).", $operation, null);
    }

    public static function notConfigured(string $what): self
    {
        return new self("The GitHub integration is not configured ({$what}).", 'configuration', null);
    }

    public static function forbiddenWrite(): self
    {
        return new self('Writing to GitHub is not allowed.', 'write', null);
    }
}
