<?php

namespace Modules\Monitoring\Application;

use DateTimeImmutable;
use DateTimeInterface;
use Modules\Platform\Contracts\Validation\InputRejected;

/** Validation of grant attributes (mirrors the table CHECKs). */
final class GrantInput
{
    public const MAX_DAYS = 365;

    public static function reason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw InputRejected::because('Reason must be 1-500 characters.');
        }

        return $reason;
    }

    public static function visibility(string $level): string
    {
        if (! in_array($level, ['standard', 'restricted'], true)) {
            throw InputRejected::because('Invalid visibility level.');
        }

        return $level;
    }

    /** ISO 8601 with offset (2026-12-01T00:00:00+00:00) or a plain date (end of that day, UTC). */
    public static function moment(string $value, string $field): DateTimeImmutable
    {
        $moment = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $value)
            ?: DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        if ($moment === false) {
            throw InputRejected::because("{$field} must be an ISO 8601 date-time or a YYYY-MM-DD date.");
        }

        return strlen($value) === 10 ? $moment->setTime(23, 59, 59) : $moment;
    }

    /**
     * @return array{0: string, 1: string} [starts_at, expires_at] as ISO 8601
     */
    public static function validity(?string $startsAt, string $expiresAt): array
    {
        $now = new DateTimeImmutable('now');
        $starts = $startsAt === null ? $now : self::moment($startsAt, 'starts_at');
        if ($starts < $now->modify('-5 minutes')) {
            throw InputRejected::because('starts_at cannot be in the past.');
        }
        $expires = self::moment($expiresAt, 'expires_at');

        if ($expires <= $starts || $expires <= $now) {
            throw InputRejected::because('expires_at must be later than starts_at and in the future.');
        }
        if ($expires > $starts->modify('+'.self::MAX_DAYS.' days')) {
            throw InputRejected::because('A grant can last at most '.self::MAX_DAYS.' days.');
        }

        return [$starts->format(DateTimeInterface::ATOM), $expires->format(DateTimeInterface::ATOM)];
    }
}
