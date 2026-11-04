<?php

namespace Modules\Work\Contracts\Monitoring;

/**
 * TRUSTED read access to work items, without an actor: it returns whatever
 * the selector covers. Only the Monitoring module may depend on it
 * (deptrac layer WorkMonitoringContracts), and only with selectors built
 * from valid grants. Must run inside a tenant context (RLS).
 */
interface MonitoredWorkReader
{
    /**
     * Keyset page ordered by updated_at desc, id desc.
     *
     * @param  array{status?: string, type?: string, priority?: string}  $filters
     * @param  array{0: string, 1: string}|null  $after  [updated_at, id] of the previous page's last row
     * @return list<MonitoredItem>
     */
    public function page(ItemSelector $selector, array $filters, ?array $after, int $limit): array;

    /**
     * Counts inside the selector only (never organization totals).
     *
     * @return array{total: int, by_status: array<string, int>, by_priority: array<string, int>}
     */
    public function counts(ItemSelector $selector): array;

    /** The item with its description, or null when the selector does not cover it. */
    public function find(ItemSelector $selector, string $itemId): ?MonitoredItem;

    public function covers(ItemSelector $selector, string $itemId): bool;
}
