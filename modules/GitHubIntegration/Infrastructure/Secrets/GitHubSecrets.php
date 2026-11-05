<?php

namespace Modules\GitHubIntegration\Infrastructure\Secrets;

use Modules\GitHubIntegration\Application\Ports\GitHubApiFailure;
use Modules\GitHubIntegration\Domain\Secret;

/**
 * Reads GitHub credentials from files (G10): never from the database, the
 * config cache or a job payload. A file readable by group or others is
 * refused, as is a missing one; errors name the secret, never its content.
 */
final class GitHubSecrets
{
    /** @param array<string, mixed> $paths */
    public function __construct(private readonly array $paths) {}

    public function privateKey(): Secret
    {
        return $this->read('private_key') ?? throw GitHubApiFailure::notConfigured('private_key');
    }

    public function oauthClientSecret(): Secret
    {
        return $this->read('oauth_client') ?? throw GitHubApiFailure::notConfigured('oauth_client');
    }

    /**
     * The current webhook secret, then the previous one while it is rotated.
     *
     * @return list<Secret>
     */
    public function webhookSecrets(): array
    {
        $current = $this->read('webhook') ?? throw GitHubApiFailure::notConfigured('webhook');
        $previous = $this->read('webhook_previous');

        return $previous === null ? [$current] : [$current, $previous];
    }

    private function read(string $name): ?Secret
    {
        $path = $this->paths[$name] ?? null;
        if (! is_string($path) || $path === '') {
            return null;
        }
        clearstatcache(true, $path);
        if (! is_file($path)) {
            return null;
        }
        $permissions = fileperms($path);
        if ($permissions === false || ($permissions & 0o077) !== 0) {
            throw GitHubApiFailure::notConfigured("{$name} must be readable by its owner only");
        }
        $value = trim((string) file_get_contents($path));

        return $value === '' ? null : new Secret($value);
    }
}
