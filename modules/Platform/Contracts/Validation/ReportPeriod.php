<?php

namespace Modules\Platform\Contracts\Validation;

use DateTimeImmutable;

/** A [from, to) date range for reports: at most 366 days, last 30 days by default. */
final readonly class ReportPeriod
{
    public const MAX_DAYS = 366;

    private function __construct(public string $from, public string $to) {}

    public static function of(?string $from, ?string $to): self
    {
        $end = $to === null ? new DateTimeImmutable('tomorrow') : self::date($to, 'to');
        $start = $from === null ? $end->modify('-30 days') : self::date($from, 'from');
        if ($start >= $end) {
            throw InputRejected::because('from must be before to.');
        }
        if ($start->modify('+'.self::MAX_DAYS.' days') < $end) {
            throw InputRejected::because('A report period can span at most '.self::MAX_DAYS.' days.');
        }

        return new self($start->format('Y-m-d'), $end->format('Y-m-d'));
    }

    private static function date(string $value, string $field): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw InputRejected::because("{$field} must be YYYY-MM-DD.");
        }

        return $date;
    }
}
