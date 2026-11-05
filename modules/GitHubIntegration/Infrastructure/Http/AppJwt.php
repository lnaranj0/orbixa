<?php

namespace Modules\GitHubIntegration\Infrastructure\Http;

use Modules\GitHubIntegration\Application\Ports\GitHubApiFailure;
use Modules\GitHubIntegration\Domain\Secret;
use Modules\GitHubIntegration\Infrastructure\Secrets\GitHubSecrets;

/**
 * Short-lived RS256 JWT that authenticates as the GitHub App. Issued per
 * call and never cached; GitHub caps its lifetime at 10 minutes.
 */
final class AppJwt
{
    public function __construct(
        private readonly GitHubSecrets $secrets,
        private readonly string $issuer,
    ) {}

    public function issue(): Secret
    {
        if ($this->issuer === '') {
            throw GitHubApiFailure::notConfigured('client_id');
        }
        $now = time();
        $unsigned = self::encode(['alg' => 'RS256', 'typ' => 'JWT']).'.'
            .self::encode(['iat' => $now - 60, 'exp' => $now + 540, 'iss' => $this->issuer]);

        $key = openssl_pkey_get_private($this->secrets->privateKey()->reveal());
        $signature = '';
        $signed = $key !== false && openssl_sign($unsigned, $signature, $key, OPENSSL_ALGO_SHA256);
        while (openssl_error_string() !== false) {
            // Drain OpenSSL's error queue so nothing about the key outlives this call.
        }
        if (! $signed) {
            throw GitHubApiFailure::notConfigured('private_key');
        }

        return new Secret($unsigned.'.'.self::base64Url($signature));
    }

    /** @param array<string, int|string> $part */
    private static function encode(array $part): string
    {
        return self::base64Url(json_encode($part, JSON_THROW_ON_ERROR));
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
