<?php

namespace Modules\Work\Domain;

/**
 * Sanitized diff for work.item_events (W9): structured fields keep their
 * old and new values; the description only records that it changed and its
 * new length, never its text.
 */
final class ActivityDiff
{
    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after  only the fields that were written
     * @return array<string, array<string, mixed>>
     */
    public static function of(array $before, array $after): array
    {
        $diff = [];
        foreach ($after as $field => $value) {
            $old = $before[$field] ?? null;
            if ($old === $value) {
                continue;
            }
            $diff[$field] = $field === 'description'
                ? ['changed' => true, 'length' => is_string($value) ? mb_strlen($value) : 0]
                : ['from' => $old, 'to' => $value];
        }

        return $diff;
    }
}
