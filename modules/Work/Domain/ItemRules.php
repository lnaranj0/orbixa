<?php

namespace Modules\Work\Domain;

/**
 * Fixed vocabulary of 03 (W2-W4). The status graph is the base flow of
 * docs/WORKFLOWS.md until 05 introduces configurable workflows.
 */
final class ItemRules
{
    public const TYPES = ['initiative', 'epic', 'feature', 'task', 'bug', 'chore', 'incident'];

    public const PRIORITIES = ['none', 'low', 'medium', 'high', 'urgent'];

    public const STATUSES = ['backlog', 'ready', 'active', 'review', 'done', 'canceled'];

    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        'backlog' => ['ready', 'canceled'],
        'ready' => ['active', 'canceled'],
        'active' => ['review', 'canceled'],
        'review' => ['active', 'done', 'canceled'],
        'done' => ['active'],
        'canceled' => ['backlog'],
    ];

    /** Transitions that need a reason (reopen, cancel, restore). */
    public static function needsReason(string $from, string $to): bool
    {
        return $to === 'canceled' || $from === 'done' || $from === 'canceled';
    }

    public static function allows(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

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
