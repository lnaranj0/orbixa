<?php

namespace Modules\Work\Infrastructure;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Modules\Work\Contracts\Monitoring\ItemSelector;
use Modules\Work\Contracts\Monitoring\MonitoredItem;
use Modules\Work\Contracts\Monitoring\MonitoredWorkReader;

/** SQL for the trusted monitoring reader; every query starts from the selector. */
final class PostgresMonitoredWorkReader implements MonitoredWorkReader
{
    private const COLUMNS = 'i.id::text as id, i.key, i.project_id::text as project_id, i.type, i.title, i.status, i.priority,
        i.due_on::text as due_on, i.completed_at::text as completed_at, i.updated_at::text as updated_at';

    public function __construct(private readonly DatabaseManager $db) {}

    public function page(ItemSelector $selector, array $filters, ?array $after, int $limit): array
    {
        if ($selector->isEmpty()) {
            return [];
        }
        [$where, $bindings] = $this->predicate($selector);
        foreach (['status', 'type', 'priority'] as $column) {
            if (isset($filters[$column])) {
                $where .= " and i.{$column} = ?";
                $bindings[] = $filters[$column];
            }
        }
        [$where, $bindings] = $this->narrowed($where, $bindings, $filters);
        if ($after !== null) {
            $where .= ' and (i.updated_at, i.id) < (?::timestamptz, ?::uuid)';
            array_push($bindings, $after[0], $after[1]);
        }
        $bindings[] = $limit;

        return array_values(array_map(fn (object $row) => self::item($row), $this->connection()->select(
            'select '.self::COLUMNS." from work.items i where {$where} order by i.updated_at desc, i.id desc limit ?",
            $bindings
        )));
    }

    public function counts(ItemSelector $selector, array $filters = []): array
    {
        $result = ['total' => 0, 'by_status' => [], 'by_priority' => []];
        if ($selector->isEmpty()) {
            return $result;
        }
        [$where, $bindings] = $this->narrowed(...[...$this->predicate($selector), $filters]);

        foreach ($this->connection()->select(
            "select i.status, i.priority, count(*) as total from work.items i where {$where} group by i.status, i.priority",
            $bindings
        ) as $row) {
            $c = (array) $row;
            $total = (int) $c['total'];
            $result['total'] += $total;
            $result['by_status'][(string) $c['status']] = ($result['by_status'][(string) $c['status']] ?? 0) + $total;
            $result['by_priority'][(string) $c['priority']] = ($result['by_priority'][(string) $c['priority']] ?? 0) + $total;
        }

        return $result;
    }

    public function find(ItemSelector $selector, string $itemId): ?MonitoredItem
    {
        if ($selector->isEmpty() || ! ItemStore::isUuid($itemId)) {
            return null;
        }
        [$where, $bindings] = $this->predicate($selector);
        $row = $this->connection()->selectOne(
            'select '.self::COLUMNS.", i.description from work.items i where i.id = ?::uuid and ({$where})",
            [$itemId, ...$bindings]
        );

        return $row === null ? null : self::item($row);
    }

    public function covers(ItemSelector $selector, string $itemId): bool
    {
        if ($selector->isEmpty() || ! ItemStore::isUuid($itemId)) {
            return false;
        }
        [$where, $bindings] = $this->predicate($selector);

        return $this->connection()->selectOne("select 1 as found from work.items i where i.id = ?::uuid and ({$where})", [$itemId, ...$bindings]) !== null;
    }

    /**
     * AND filters by person or teams on top of the selector.
     *
     * @param  list<mixed>  $bindings
     * @param  array{assignee_membership_id?: string, team_ids?: list<string>}  $filters
     * @return array{0: string, 1: list<mixed>}
     */
    private function narrowed(string $where, array $bindings, array $filters): array
    {
        if (isset($filters['assignee_membership_id'])) {
            $where .= ' and exists (select 1 from work.item_assignments a where a.item_id = i.id and a.removed_at is null and a.membership_id = ?::uuid)';
            $bindings[] = $filters['assignee_membership_id'];
        }
        if (isset($filters['team_ids'])) {
            $where .= ' and exists (select 1 from work.item_team_links l where l.item_id = i.id and l.unlinked_at is null and l.team_id = any(?::uuid[]))';
            $bindings[] = ItemStore::pgArray($filters['team_ids']);
        }

        return [$where, $bindings];
    }

    /**
     * OR of the selector clauses; values always travel as bindings.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function predicate(ItemSelector $selector): array
    {
        $parts = [];
        $bindings = [];
        foreach ($selector->clauses as $clause) {
            $projects = ItemStore::pgArray($clause['project_ids']);
            $parts[] = match ($clause['kind']) {
                ItemSelector::ASSIGNEES => '(i.project_id = any(?::uuid[]) and exists (select 1 from work.item_assignments a
                    where a.item_id = i.id and a.removed_at is null and a.membership_id = any(?::uuid[])))',
                ItemSelector::TEAMS => '(i.project_id = any(?::uuid[]) and exists (select 1 from work.item_team_links l
                    where l.item_id = i.id and l.unlinked_at is null and l.team_id = any(?::uuid[])))',
                default => '(i.project_id = any(?::uuid[]))',
            };
            $bindings[] = $projects;
            if ($clause['kind'] !== ItemSelector::ALL) {
                $bindings[] = ItemStore::pgArray($clause['ids']);
            }
        }

        return ['('.implode(' or ', $parts).')', $bindings];
    }

    private static function item(object $row): MonitoredItem
    {
        $c = (array) $row;
        $nullable = fn (string $key) => ($c[$key] ?? null) === null ? null : (string) $c[$key];

        return new MonitoredItem(
            (string) $c['id'], (string) $c['key'], (string) $c['project_id'], (string) $c['type'], (string) $c['title'],
            (string) $c['status'], (string) $c['priority'], $nullable('due_on'), $nullable('completed_at'),
            (string) $c['updated_at'], $nullable('description'),
        );
    }

    private function connection(): Connection
    {
        return $this->db->connection();
    }
}
