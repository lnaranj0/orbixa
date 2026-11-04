<?php

namespace Modules\Workflow\Infrastructure;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Modules\Workflow\Contracts\WorkflowRuleViolation;

/**
 * Workflow tables only, inside the caller's tenant context (RLS).
 *
 * @phpstan-type StatusRow array{id: string, name: string, category: string, position: int, is_initial: bool, wip_limit: int|null, archived_at: string|null}
 * @phpstan-type TransitionRow array{id: string, from_status_id: string, to_status_id: string, requires_reason: bool, check_blockers: bool, required_permission: string, disabled_at: string|null}
 */
final class WorkflowStore
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function __construct(private readonly DatabaseManager $db) {}

    /**
     * @return array{id: string, version: int}|null
     */
    public function workflowOf(string $projectId): ?array
    {
        $row = $this->connection()->selectOne('select id::text as id, version from workflow.workflows where project_id = ?::uuid', [$projectId]);

        return $row === null ? null : ['id' => (string) ((array) $row)['id'], 'version' => (int) ((array) $row)['version']];
    }

    /** Optimistic bump of the configuration version: false when stale. */
    public function bumpVersion(string $workflowId, int $expectedVersion): bool
    {
        return $this->connection()->update(
            'update workflow.workflows set version = version + 1, updated_at = now(), lock_version = lock_version + 1
              where id = ?::uuid and version = ?',
            [$workflowId, $expectedVersion]
        ) === 1;
    }

    /**
     * @return list<StatusRow>
     */
    public function statuses(string $workflowId, bool $includeArchived = true): array
    {
        return array_values(array_map(fn (object $row) => self::status($row), $this->connection()->select(
            'select id::text as id, name, category, position, is_initial, wip_limit, archived_at::text as archived_at
               from workflow.statuses where workflow_id = ?::uuid'.($includeArchived ? '' : ' and archived_at is null').' order by position, id',
            [$workflowId]
        )));
    }

    /**
     * @return StatusRow|null
     */
    public function findStatus(string $workflowId, string $statusId): ?array
    {
        if (! self::isUuid($statusId)) {
            return null;
        }
        $row = $this->connection()->selectOne(
            'select id::text as id, name, category, position, is_initial, wip_limit, archived_at::text as archived_at
               from workflow.statuses where workflow_id = ?::uuid and id = ?::uuid',
            [$workflowId, $statusId]
        );

        return $row === null ? null : self::status($row);
    }

    public function addStatus(string $projectId, string $workflowId, string $name, string $category, int $position, ?int $wipLimit): string
    {
        return $this->guarded(fn () => (string) ((array) $this->connection()->selectOne(
            'insert into workflow.statuses (organization_id, project_id, workflow_id, name, category, position, wip_limit)
             values (platform.current_organization_id(), ?::uuid, ?::uuid, ?, ?, ?, ?) returning id::text as id',
            [$projectId, $workflowId, $name, $category, $position, $wipLimit]
        ))['id']);
    }

    /**
     * @param  array{name?: string, position?: int, wip_limit?: int|null, archived?: bool}  $changes
     */
    public function updateStatus(string $statusId, array $changes): void
    {
        $sets = [];
        $bindings = [];
        foreach (['name', 'position', 'wip_limit'] as $column) {
            if (array_key_exists($column, $changes)) {
                $sets[] = "{$column} = ?";
                $bindings[] = $changes[$column];
            }
        }
        if (($changes['archived'] ?? false) === true) {
            $sets[] = 'archived_at = now()';
        }
        if ($sets === []) {
            return;
        }
        $bindings[] = $statusId;
        $this->guarded(fn () => $this->connection()->update('update workflow.statuses set '.implode(', ', $sets).' where id = ?::uuid', $bindings));
    }

    /**
     * @return list<TransitionRow>
     */
    public function transitions(string $workflowId): array
    {
        return array_values(array_map(fn (object $row) => self::transition($row), $this->connection()->select(
            'select id::text as id, from_status_id::text as from_status_id, to_status_id::text as to_status_id, requires_reason, check_blockers,
                    required_permission, disabled_at::text as disabled_at
               from workflow.transitions where workflow_id = ?::uuid order by created_at, id',
            [$workflowId]
        )));
    }

    /**
     * @return TransitionRow|null
     */
    public function findTransition(string $workflowId, string $transitionId): ?array
    {
        if (! self::isUuid($transitionId)) {
            return null;
        }
        $row = $this->connection()->selectOne(
            'select id::text as id, from_status_id::text as from_status_id, to_status_id::text as to_status_id, requires_reason, check_blockers,
                    required_permission, disabled_at::text as disabled_at
               from workflow.transitions where workflow_id = ?::uuid and id = ?::uuid',
            [$workflowId, $transitionId]
        );

        return $row === null ? null : self::transition($row);
    }

    public function addTransition(string $workflowId, string $from, string $to, bool $requiresReason, bool $checkBlockers, string $permission): string
    {
        return $this->guarded(fn () => (string) ((array) $this->connection()->selectOne(
            'insert into workflow.transitions (organization_id, workflow_id, from_status_id, to_status_id, requires_reason, check_blockers, required_permission)
             values (platform.current_organization_id(), ?::uuid, ?::uuid, ?::uuid, ?, ?, ?) returning id::text as id',
            [$workflowId, $from, $to, $requiresReason, $checkBlockers, $permission]
        ))['id']);
    }

    /**
     * @param  array{requires_reason?: bool, check_blockers?: bool, required_permission?: string, disabled?: bool}  $changes
     */
    public function updateTransition(string $transitionId, array $changes): void
    {
        $sets = [];
        $bindings = [];
        foreach (['requires_reason', 'check_blockers', 'required_permission'] as $column) {
            if (array_key_exists($column, $changes)) {
                $sets[] = "{$column} = ?";
                $bindings[] = $changes[$column];
            }
        }
        if (($changes['disabled'] ?? false) === true) {
            $sets[] = 'disabled_at = now()';
        }
        if ($sets === []) {
            return;
        }
        $bindings[] = $transitionId;
        $this->guarded(fn () => $this->connection()->update('update workflow.transitions set '.implode(', ', $sets).' where id = ?::uuid', $bindings));
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
                $state === '23505' && str_contains($message, 'statuses_name_unique') => WorkflowRuleViolation::because(WorkflowRuleViolation::NAME_TAKEN),
                $state === '23505' && str_contains($message, 'transitions_one_active') => WorkflowRuleViolation::because(WorkflowRuleViolation::DUPLICATE),
                $state === '23514' && str_contains($message, 'transitions into done') => WorkflowRuleViolation::because(WorkflowRuleViolation::DONE_APPROVAL),
                $state === '23514', $state === '23503' => WorkflowRuleViolation::because(WorkflowRuleViolation::STATUS),
                default => $exception,
            };
        }
    }

    private function connection(): Connection
    {
        return $this->db->connection();
    }

    /**
     * @return StatusRow
     */
    private static function status(object $row): array
    {
        $c = (array) $row;

        return [
            'id' => (string) $c['id'], 'name' => (string) $c['name'], 'category' => (string) $c['category'],
            'position' => (int) $c['position'], 'is_initial' => (bool) $c['is_initial'],
            'wip_limit' => $c['wip_limit'] === null ? null : (int) $c['wip_limit'],
            'archived_at' => $c['archived_at'] === null ? null : (string) $c['archived_at'],
        ];
    }

    /**
     * @return TransitionRow
     */
    private static function transition(object $row): array
    {
        $c = (array) $row;

        return [
            'id' => (string) $c['id'], 'from_status_id' => (string) $c['from_status_id'], 'to_status_id' => (string) $c['to_status_id'],
            'requires_reason' => (bool) $c['requires_reason'], 'check_blockers' => (bool) $c['check_blockers'],
            'required_permission' => (string) $c['required_permission'],
            'disabled_at' => $c['disabled_at'] === null ? null : (string) $c['disabled_at'],
        ];
    }
}
