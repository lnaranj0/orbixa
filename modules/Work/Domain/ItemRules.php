<?php

namespace Modules\Work\Domain;

/**
 * Fixed vocabulary of 03 (W2-W4). STATUSES are the global categories; the
 * allowed transitions live in each project's workflow (05).
 */
final class ItemRules
{
    public const TYPES = ['initiative', 'epic', 'feature', 'task', 'bug', 'chore', 'incident'];

    public const PRIORITIES = ['none', 'low', 'medium', 'high', 'urgent'];

    public const STATUSES = ['backlog', 'ready', 'active', 'review', 'done', 'canceled'];

    public static function rank(string $type): int
    {
        return match ($type) {
            'initiative' => 1,
            'epic' => 2,
            'feature' => 3,
            default => 4,
        };
    }

    /** Mirrors work.enforce_item_hierarchy(): a higher rank, or a subtask under a task. */
    public static function canParent(string $parentType, string $childType): bool
    {
        return self::rank($childType) > self::rank($parentType)
            || (self::rank($childType) === 4 && $parentType === 'task');
    }
}
