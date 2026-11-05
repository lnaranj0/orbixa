<?php

namespace Modules\Platform\Infrastructure\Logging;

use Illuminate\Log\Logger;
use Monolog\LogRecord;

/**
 * Log channel tap that masks credentials before any handler writes them:
 * Authorization and Bearer values, GitHub token prefixes, JWTs and
 * PEM private keys, in the message and (recursively) in string context.
 * A safety net only: code must not log credentials in the first place.
 */
final class RedactCredentials
{
    private const MASK = '[redacted]';

    /** Pattern => replacement. */
    private const PATTERNS = [
        '/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?(-----END [A-Z ]*PRIVATE KEY-----|$)/s' => self::MASK,
        '/\b(authorization|proxy-authorization)\b(["\']?\s*[:=]\s*["\']?)[^\r\n"\',}]+/i' => '$1$2'.self::MASK,
        '/\b(bearer)\s+[A-Za-z0-9._~+\/=-]{8,}/i' => '$1 '.self::MASK,
        '/\bgh[opsuR]_[A-Za-z0-9]{20,}\b/' => self::MASK,
        '/\bgithub_pat_[A-Za-z0-9_]{20,}\b/' => self::MASK,
        '/\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]+/' => self::MASK,
    ];

    public function __invoke(Logger $logger): void
    {
        $logger->pushProcessor(fn (LogRecord $record): LogRecord => $record->with(
            message: self::redact($record->message),
            context: self::redactArray($record->context),
        ));
    }

    public static function redact(string $text): string
    {
        foreach (self::PATTERNS as $pattern => $replacement) {
            $text = (string) preg_replace($pattern, $replacement, $text);
        }

        return $text;
    }

    /**
     * @param  array<mixed>  $context
     * @return array<mixed>
     */
    private static function redactArray(array $context): array
    {
        foreach ($context as $key => $value) {
            if (is_string($key) && preg_match('/authorization|secret|token|password|private_key/i', $key) === 1 && (is_string($value) || is_array($value))) {
                $context[$key] = self::MASK;
            } elseif (is_string($value)) {
                $context[$key] = self::redact($value);
            } elseif (is_array($value)) {
                $context[$key] = self::redactArray($value);
            }
        }

        return $context;
    }
}
