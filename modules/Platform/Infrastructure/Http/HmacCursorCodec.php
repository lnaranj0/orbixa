<?php

namespace Modules\Platform\Infrastructure\Http;

use Modules\Platform\Contracts\Http\CursorCodec;
use Modules\Platform\Contracts\Http\InvalidCursor;

/** base64url(json) + "." + base64url(HMAC-SHA256), keyed by a secret derived from APP_KEY. */
final class HmacCursorCodec implements CursorCodec
{
    public function __construct(private readonly string $appKey) {}

    public function encode(string $resource, array $position): string
    {
        $payload = self::base64(json_encode(['r' => $resource, 'p' => $position], JSON_THROW_ON_ERROR));

        return $payload.'.'.self::base64($this->sign($payload));
    }

    public function decode(string $resource, string $cursor): array
    {
        if (strlen($cursor) > 1024 || substr_count($cursor, '.') !== 1) {
            throw InvalidCursor::make();
        }
        [$payload, $signature] = explode('.', $cursor);

        if (! hash_equals(self::base64($this->sign($payload)), $signature)) {
            throw InvalidCursor::make();
        }

        $json = base64_decode(strtr($payload, '-_', '+/'), true);
        $data = $json === false ? null : json_decode($json, true);

        if (! is_array($data) || ($data['r'] ?? null) !== $resource || ! is_array($data['p'] ?? null) || ! array_is_list($data['p'])) {
            throw InvalidCursor::make();
        }
        foreach ($data['p'] as $value) {
            if (! is_string($value)) {
                throw InvalidCursor::make();
            }
        }

        return $data['p'];
    }

    private function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, hash_hmac('sha256', 'orbixa.cursor.v1', $this->appKey, true), true);
    }

    private static function base64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
