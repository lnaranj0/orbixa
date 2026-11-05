<?php

namespace Modules\GitHubIntegration\Infrastructure\Http;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use Modules\GitHubIntegration\Domain\Secret;

/**
 * Installation tokens (1 h) cached encrypted with APP_KEY, expiring at least
 * five minutes before GitHub does (G10). The store only ever sees ciphertext.
 */
final class InstallationTokenCache
{
    private const MARGIN_SECONDS = 300;

    private const MAX_TTL_SECONDS = 3300;

    public function __construct(
        private readonly Repository $store,
        private readonly Encrypter $encrypter,
    ) {}

    public function get(int $installationId): ?Secret
    {
        $cached = $this->store->get(self::key($installationId));
        if (! is_string($cached)) {
            return null;
        }
        try {
            return new Secret($this->encrypter->decryptString($cached));
        } catch (DecryptException) {
            $this->forget($installationId);

            return null;
        }
    }

    public function put(int $installationId, Secret $token, int $expiresAt): void
    {
        $ttl = min($expiresAt - time() - self::MARGIN_SECONDS, self::MAX_TTL_SECONDS);
        if ($ttl <= 0) {
            return;
        }
        $this->store->put(self::key($installationId), $this->encrypter->encryptString($token->reveal()), $ttl);
    }

    public function forget(int $installationId): void
    {
        $this->store->forget(self::key($installationId));
    }

    public static function key(int $installationId): string
    {
        return "github:installation-token:{$installationId}";
    }
}
