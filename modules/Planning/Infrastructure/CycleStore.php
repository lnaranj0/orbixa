<?php

namespace Modules\Planning\Infrastructure;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Modules\Planning\Contracts\PlanningRuleViolation;

/**
 * Planning tables only, inside the caller's tenant context (RLS).
 *
 * @phpstan-type CycleRow array{id: string, project_id: string, name: string, goal: string|null, starts_on: string, ends_on: string, capacity: string|null, status: string, closed_at: string|null, version: int}
 */
final class CycleStore
{
    private const COLUMNS = 'id::text as id, project_id::text as project_id, name, goal, starts_on::text as starts_on, ends_on::text as ends_on,
        capacity::text as capacity, status, closed_at::text as closed_at, lock_version';

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function __construct(private readonly DatabaseManager $db) {}

    public function mode(string $projectId): string
    {
        $row = $this->connection()->selectOne('select mode from planning.project_settings where project_id = ?::uuid', [$projectId]);

        return $row === null ? 'continuous' : (string) ((array) $row)['mode'];
    }

    public function setMode(string $projectId, string $mode): void
    {
        $this->connection()->statement(
            'insert into planning.project_settings (organization_id, project_id, mode) values (platform.current_organization_id(), ?::uuid, ?)
             on conflict (organization_id, project_id) do update set mode = excluded.mode, updated_at = now(),
                 lock_version = planning.project_settings.lock_version + 1',
            [$projectId, $mode]
        );
    }

    public function create(string $projectId, string $name, ?string $goal, string $startsOn, string $endsOn, ?string $capacity, string $createdBy): string
    {
        return $this->guarded(fn () => (string) ((array) $this->connection()->selectOne(
            'insert into planning.cycles (organization_id, project_id, name, goal, starts_on, ends_on, capacity, created_by_membership_id)
             values (platform.current_organization_id(), ?::uuid, ?, ?, ?::date, ?::date, ?::numeric, ?::uuid) returning id::text as id',
            [$projectId, $name, $goal, $startsOn, $endsOn, $capacity, $createdBy]
        ))['id']);
    }

    /**
     * @return CycleRow|null
     */
    public function find(string $cycleId, bool $forUpdate = false): ?array
    {
        if (! self::isUuid($cycleId)) {
            return null;
        }
        $row = $this->connection()->selectOne('select '.self::COLUMNS.' from planning.cycles where id = ?::uuid'.($forUpdate ? ' for update' : ''), [$cycleId]);

        return $row === null ? null : self::row($row);
    }

    /**
     * @return list<CycleRow>
     */
    public function ofProject(string $projectId): array
    {
        return array_values(array_map(fn (object $row) => self::row($row), $this->connection()->select(
            'select '.self::COLUMNS.' from planning.cycles where project_id = ?::uuid order by starts_on desc, id desc limit 100',
            [$projectId]
        )));
    }

    public function setStatus(string $cycleId, string $status): void
    {
        $this->guarded(fn () => $this->connection()->update(
            "update planning.cycles set status = ?, closed_at = case when ? = 'closed' then now() else null end,
                    updated_at = now(), lock_version = lock_version + 1 where id = ?::uuid",
            [$status, $status, $cycleId]
        ));
    }

    public function addItem(string $projectId, string $cycleId, string $itemId, bool $committed, ?string $carriedFrom, string $addedBy): void
    {
        $this->guarded(fn () => $this->connection()->insert(
            'insert into planning.cycle_items (organization_id, project_id, cycle_id, item_id, committed, carried_from_cycle_id, added_by_membership_id)
             values (platform.current_organization_id(), ?::uuid, ?::uuid, ?::uuid, ?, ?::uuid, ?::uuid)',
            [$projectId, $cycleId, $itemId, $committed, $carriedFrom, $addedBy]
        ));
    }

    public function endItem(string $cycleId, string $itemId, string $outcome, string $endedBy): int
    {
        return $this->connection()->update(
            'update planning.cycle_items set outcome = ?, ended_at = now(), ended_by_membership_id = ?::uuid
              where cycle_id = ?::uuid and item_id = ?::uuid and outcome is null',
            [$outcome, $endedBy, $cycleId, $itemId]
        );
    }

    /**
     * @return list<array{item_id: string, committed: bool, outcome: string|null, carried_from_cycle_id: string|null}>
     */
    public function items(string $cycleId, bool $openOnly = false): array
    {
        return array_values(array_map(function (object $row): array {
            $c = (array) $row;

            return [
                'item_id' => (string) $c['item_id'], 'committed' => (bool) $c['committed'],
                'outcome' => $c['outcome'] === null ? null : (string) $c['outcome'],
                'carried_from_cycle_id' => $c['carried_from_cycle_id'] === null ? null : (string) $c['carried_from_cycle_id'],
            ];
        }, $this->connection()->select(
            'select item_id::text as item_id, committed, outcome, carried_from_cycle_id::text as carried_from_cycle_id
               from planning.cycle_items where cycle_id = ?::uuid'.($openOnly ? ' and outcome is null' : '').' order by added_at, id',
            [$cycleId]
        )));
    }

    public static function isUuid(?string $value): bool
    {
        return $value !== null && preg_match(self::UUID, $value) === 1;
    }

    /**
     * @template TResult
     *
     * @param  \Closure(): TResult  $operation
     * @return TResult
     */
    private function guarded(\Closure $operation): mixed
    {
        try {
            return $this->connection()->transaction($operation);
        } catch (QueryException $exception) {
            $state = $exception->errorInfo[0] ?? null;
            $message = $exception->getMessage();

            throw match (true) {
                $state === '23505' && str_contains($message, 'cycles_one_active') => PlanningRuleViolation::because(PlanningRuleViolation::ACTIVE_EXISTS),
                $state === '23505' && str_contains($message, 'cycle_items_one_open') => PlanningRuleViolation::because(PlanningRuleViolation::IN_ANOTHER_CYCLE),
                $state === '23514' && str_contains($message, 'cycle is closed') => PlanningRuleViolation::because(PlanningRuleViolation::CLOSED),
                $state === '23503' && str_contains($message, 'cycle_items_item_fk') => PlanningRuleViolation::because(PlanningRuleViolation::ITEM),
                $state === '23514' && str_contains($message, 'cycles_dates_check') => PlanningRuleViolation::because(PlanningRuleViolation::STATE),
                default => $exception,
            };
        }
    }

    private function connection(): Connection
    {
        return $this->db->connection();
    }

    /**
     * @return CycleRow
     */
    private static function row(object $row): array
    {
        $c = (array) $row;
        $nullable = fn (string $key) => $c[$key] === null ? null : (string) $c[$key];

        return [
            'id' => (string) $c['id'], 'project_id' => (string) $c['project_id'], 'name' => (string) $c['name'],
            'goal' => $nullable('goal'), 'starts_on' => (string) $c['starts_on'], 'ends_on' => (string) $c['ends_on'],
            'capacity' => $nullable('capacity'), 'status' => (string) $c['status'], 'closed_at' => $nullable('closed_at'),
            'version' => (int) $c['lock_version'],
        ];
    }
}
