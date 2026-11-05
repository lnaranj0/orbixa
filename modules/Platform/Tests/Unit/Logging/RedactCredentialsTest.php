<?php

namespace Modules\Platform\Tests\Unit\Logging;

use Modules\Platform\Infrastructure\Logging\RedactCredentials;
use Monolog\Handler\NullHandler;
use Tests\TestCase;

/** Credential masking on every log channel (06 G10, global review B2). */
final class RedactCredentialsTest extends TestCase
{
    public function test_every_writing_channel_is_tapped(): void
    {
        foreach (config('logging.channels') as $name => $channel) {
            // "emergency" has no driver: Laravel's last-resort fallback, it cannot be tapped.
            if (! isset($channel['driver']) || ($channel['handler'] ?? null) === NullHandler::class) {
                continue;
            }
            $this->assertContains(RedactCredentials::class, $channel['tap'] ?? [], "Channel {$name} is not redacted.");
        }
    }

    public function test_credentials_are_masked_even_when_truncated(): void
    {
        $token = 'ghs_'.str_repeat('A1b2', 9);
        $text = RedactCredentials::redact(implode("\n", [
            'Authorization: Bearer '.$token,
            'retry with bearer '.$token,
            'trace arg '.substr($token, 0, 15),
            'jwt eyJhbGciOiJSUzI1NiJ9.eyJpc3MiOiIxMjMifQ.c2lnbmF0dXJl',
            "-----BEGIN RSA PRIVATE KEY-----\nMIIEow\n-----END RSA PRIVATE KEY-----",
        ]));

        $this->assertStringNotContainsString(substr($token, 0, 15), $text);
        $this->assertStringNotContainsString('eyJpc3MiOiIxMjMifQ', $text);
        $this->assertStringNotContainsString('MIIEow', $text);
        $this->assertStringContainsString('Authorization: [redacted]', $text);
    }
}
