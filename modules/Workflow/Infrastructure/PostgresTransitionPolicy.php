<?php

namespace Modules\Workflow\Infrastructure;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Modules\Work\Contracts\Workflow\TransitionPolicy;
use Modules\Work\Contracts\Workflow\TransitionRule;
use Modules\Work\Contracts\Workflow\WorkflowStatus;
use Modules\Workflow\Domain\DefaultWorkflow;
use RuntimeException;

/** Workflow tables only, inside the caller's tenant context (RLS). */
final class PostgresTransitionPolicy implements TransitionPolicy
{
    private const STATUS_COLUMNS = 's.id::text as id, s.name, s.category, s.position, s.wip_limit';

    public function __construct(private readonly DatabaseManager $db) {}

    public function ensureWorkflow(string $projectId): void
    {
        // Concurrent first items race on the unique (organization, project):
        // only the winner seeds statuses and transitions.
        $created = $this->connection()->selectOne(
            'insert into workflow.workflows (organization_id, project_id) values (platform.current_organization_id(), ?::uuid)
             on conflict (organization_id, project_id) do nothing returning id::text as id',
            [$projectId]
        );
        if ($created === null) {
            return;
        }
        $workflowId = (string) ((array) $created)['id'];

        $ids = [];
        foreach (DefaultWorkflow::STATUSES as $status) {
            $ids[$status['category']] = (string) ((array) $this->connection()->selectOne(
                'insert into workflow.statuses (organization_id, project_id, workflow_id, name, category, position, is_initial)
                 values (platform.current_organization_id(), ?::uuid, ?::uuid, ?, ?, ?, ?) returning id::text as id',
                [$projectId, $workflowId, $status['name'], $status['category'], $status['position'], $status['initial']]
            ))['id'];
        }
        foreach (DefaultWorkflow::TRANSITIONS as [$from, $to, $reason, $permission]) {
            $this->connection()->insert(
                'insert into workflow.transitions (organization_id, workflow_id, from_status_id, to_status_id, requires_reason, required_permission)
                 values (platform.current_organization_id(), ?::uuid, ?::uuid, ?::uuid, ?, ?)',
                [$workflowId, $ids[$from], $ids[$to], $reason, $permission]
            );
        }
    }

    public function initialStatus(string $projectId): WorkflowStatus
    {
        $row = $this->connection()->selectOne(
            'select '.self::STATUS_COLUMNS.' from workflow.statuses s where s.project_id = ?::uuid and s.is_initial',
            [$projectId]
        );

        return $row === null ? throw new RuntimeException('The project has no workflow.') : self::toStatus($row);
    }

    public function status(string $projectId, string $statusId): ?WorkflowStatus
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $statusId) !== 1) {
            return null;
        }
        $row = $this->connection()->selectOne(
            'select '.self::STATUS_COLUMNS.' from workflow.statuses s where s.project_id = ?::uuid and s.id = ?::uuid and s.archived_at is null',
            [$projectId, $statusId]
        );

        return $row === null ? null : self::toStatus($row);
    }

    public function statusForCategory(string $projectId, string $category): ?WorkflowStatus
    {
        $row = $this->connection()->selectOne(
            'select '.self::STATUS_COLUMNS.' from workflow.statuses s
              where s.project_id = ?::uuid and s.category = ? and s.archived_at is null order by s.position, s.id limit 1',
            [$projectId, $category]
        );

        return $row === null ? null : self::toStatus($row);
    }

    public function transition(string $projectId, string $fromStatusId, string $toStatusId): ?TransitionRule
    {
        $row = $this->connection()->selectOne(
            'select '.self::STATUS_COLUMNS.', t.requires_reason, t.check_blockers, t.required_permission
               from workflow.transitions t
               join workflow.statuses s on s.id = t.to_status_id
               join workflow.statuses f on f.id = t.from_status_id
              where f.project_id = ?::uuid and t.from_status_id = ?::uuid and t.to_status_id = ?::uuid
                and t.disabled_at is null and s.archived_at is null',
            [$projectId, $fromStatusId, $toStatusId]
        );

        return $row === null ? null : self::rule($row);
    }

    public function transitionsFrom(string $projectId, string $fromStatusId): array
    {
        return array_values(array_map(fn (object $row) => self::rule($row), $this->connection()->select(
            'select '.self::STATUS_COLUMNS.', t.requires_reason, t.check_blockers, t.required_permission
               from workflow.transitions t
               join workflow.statuses s on s.id = t.to_status_id
               join workflow.statuses f on f.id = t.from_status_id
              where f.project_id = ?::uuid and t.from_status_id = ?::uuid and t.disabled_at is null and s.archived_at is null
              order by s.position, s.id',
            [$projectId, $fromStatusId]
        )));
    }

    public function statusesOf(string $projectId): array
    {
        return array_values(array_map(fn (object $row) => self::toStatus($row), $this->connection()->select(
            'select '.self::STATUS_COLUMNS.' from workflow.statuses s where s.project_id = ?::uuid and s.archived_at is null order by s.position, s.id',
            [$projectId]
        )));
    }

    private static function toStatus(object $row): WorkflowStatus
    {
        $c = (array) $row;

        return new WorkflowStatus((string) $c['id'], (string) $c['name'], (string) $c['category'], (int) $c['position'],
            $c['wip_limit'] === null ? null : (int) $c['wip_limit']);
    }

    private static function rule(object $row): TransitionRule
    {
        $c = (array) $row;

        return new TransitionRule(self::toStatus($row), (bool) $c['requires_reason'], (bool) $c['check_blockers'], (string) $c['required_permission']);
    }

    private function connection(): Connection
    {
        return $this->db->connection();
    }
}
