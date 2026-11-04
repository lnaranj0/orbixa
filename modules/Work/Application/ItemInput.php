<?php

namespace Modules\Work\Application;

use Modules\Platform\Contracts\Validation\InputRejected;
use Modules\Work\Domain\ItemRules;

/** Validation of work item attributes (mirrors the table CHECKs). */
final class ItemInput
{
    public static function title(string $title): string
    {
        $title = trim($title);
        if ($title === '' || mb_strlen($title) > 200) {
            throw InputRejected::because('Title must be 1-200 characters.');
        }

        return $title;
    }

    public static function description(?string $description): ?string
    {
        $description = $description === null ? null : trim($description);
        if ($description !== null && mb_strlen($description) > 20000) {
            throw InputRejected::because('Description must be at most 20000 characters.');
        }

        return $description === '' ? null : $description;
    }

    public static function type(string $type): string
    {
        return self::oneOf('type', $type, ItemRules::TYPES);
    }

    public static function priority(string $priority): string
    {
        return self::oneOf('priority', $priority, ItemRules::PRIORITIES);
    }

    public static function estimate(int|float|string|null $estimate): ?string
    {
        if ($estimate === null || $estimate === '') {
            return null;
        }
        if (! is_numeric($estimate) || (float) $estimate < 0 || (float) $estimate > 9999.99) {
            throw InputRejected::because('Estimate must be between 0 and 9999.99.');
        }

        return number_format((float) $estimate, 2, '.', '');
    }

    public static function dueOn(?string $dueOn): ?string
    {
        if ($dueOn === null || $dueOn === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $dueOn);
        if ($date === false || $date->format('Y-m-d') !== $dueOn) {
            throw InputRejected::because('Due date must be YYYY-MM-DD.');
        }

        return $dueOn;
    }

    /**
     * @param  list<string>  $allowed
     */
    private static function oneOf(string $field, string $value, array $allowed): string
    {
        if (! in_array($value, $allowed, true)) {
            throw InputRejected::because("Invalid {$field}.");
        }

        return $value;
    }
}
