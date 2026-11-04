<?php

namespace Modules\Portfolio\Application;

use Modules\Platform\Contracts\Validation\InputRejected;

/** Shared validation of project attributes (mirrors the table CHECKs). */
final class ProjectInput
{
    public static function name(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) {
            throw InputRejected::because('Project name must be 1-120 characters.');
        }

        return $name;
    }

    public static function description(?string $description): ?string
    {
        $description = $description === null ? null : trim($description);
        if ($description !== null && mb_strlen($description) > 5000) {
            throw InputRejected::because('Project description must be at most 5000 characters.');
        }

        return $description === '' ? null : $description;
    }

    /**
     * @param  list<string>  $allowed
     */
    public static function oneOf(string $field, string $value, array $allowed): void
    {
        if (! in_array($value, $allowed, true)) {
            throw InputRejected::because("Invalid project {$field}.");
        }
    }
}
