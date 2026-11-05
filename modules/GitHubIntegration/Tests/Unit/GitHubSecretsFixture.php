<?php

namespace Modules\GitHubIntegration\Tests\Unit;

/**
 * Throwaway credentials on disk, laid out like .secrets/ (mode 600). Values
 * are random sentinels so a leak test can search for them anywhere.
 */
final class GitHubSecretsFixture
{
    public readonly string $directory;

    public readonly string $privateKey;

    public readonly string $publicKey;

    public readonly string $webhookSecret;

    public readonly string $oauthClientSecret;

    public function __construct()
    {
        $this->directory = sys_get_temp_dir().'/orbixa-github-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0o700);

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false || ! openssl_pkey_export($key, $privateKey)) {
            throw new \RuntimeException('Could not generate a test key.');
        }
        $details = openssl_pkey_get_details($key);
        $this->privateKey = (string) $privateKey;
        $this->publicKey = is_array($details) ? (string) $details['key'] : '';
        $this->webhookSecret = 'whsec-'.bin2hex(random_bytes(16));
        $this->oauthClientSecret = 'oauthsec-'.bin2hex(random_bytes(16));

        $this->write('github-app.pem', $this->privateKey);
        $this->write('github-webhook', $this->webhookSecret);
        $this->write('github-oauth-client', $this->oauthClientSecret);
    }

    /** @return array<string, string> */
    public function paths(): array
    {
        return [
            'private_key' => $this->directory.'/github-app.pem',
            'webhook' => $this->directory.'/github-webhook',
            'webhook_previous' => $this->directory.'/github-webhook.previous',
            'oauth_client' => $this->directory.'/github-oauth-client',
        ];
    }

    public function write(string $name, string $value, int $mode = 0o600): void
    {
        $path = $this->directory.'/'.$name;
        file_put_contents($path, $value);
        chmod($path, $mode);
    }

    public function remove(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }
}
