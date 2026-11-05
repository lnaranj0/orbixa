<?php

namespace Modules\GitHubIntegration\Infrastructure\Webhooks;

use Modules\GitHubIntegration\Infrastructure\Secrets\GitHubSecrets;

/**
 * X-Hub-Signature-256 over the raw body bytes, compared in constant time
 * against the current secret and, while rotating, the previous one (G3).
 */
final class WebhookSignature
{
    public function __construct(private readonly GitHubSecrets $secrets) {}

    public function matches(string $body, ?string $header): bool
    {
        if ($header === null || preg_match('/^sha256=([0-9a-f]{64})$/', $header, $match) !== 1) {
            return false;
        }
        $matched = false;
        foreach ($this->secrets->webhookSecrets() as $secret) {
            // No early exit: every configured secret is checked.
            $matched = hash_equals(hash_hmac('sha256', $body, $secret->reveal()), $match[1]) || $matched;
        }

        return $matched;
    }
}
