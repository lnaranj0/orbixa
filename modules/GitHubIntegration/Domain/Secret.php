<?php

namespace Modules\GitHubIntegration\Domain;

/**
 * A credential held only in memory (G10): App private key, webhook secret,
 * OAuth client secret, JWT or token. It never prints, never serializes (so it
 * cannot ride in a job payload or a cache entry by accident) and is revealed
 * only at the point of use.
 */
final class Secret
{
    public function __construct(#[\SensitiveParameter] private readonly string $value)
    {
        if ($value === '') {
            throw new \InvalidArgumentException('A secret cannot be empty.');
        }
    }

    public function reveal(): string
    {
        return $this->value;
    }

    /** @return array{value: string} */
    public function __debugInfo(): array
    {
        return ['value' => '[redacted]'];
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new \LogicException('Secrets are never serialized.');
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('Secrets are never serialized.');
    }
}
