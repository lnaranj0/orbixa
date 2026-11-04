<?php

namespace Modules\Work\Infrastructure;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Modules\Work\Contracts\WorkRuleViolation;
use Modules\Work\Contracts\WorkVersionConflict;

/**
 * Work-owned tables only, always inside the caller's tenant context (RLS
 * scopes them to the active organization). It does NOT apply project
 * visibility: callers go through WorkGuard / ProjectDirectory first.
 *
 * @phpstan-type ItemRow array{
 *     id: string, project_id: string, number: int, key: string, type: string, parent_id: string|null,
 *     title: string, description: string|null, status: string, priority: string, estimate: string|null,
 *     due_on: string|null, completed_at: string|null, created_at: string, updated_at: string, version: int
 * }
 */
final class ItemStore
{
    private const COLUMNS = 'id::text as id, project_id::text as project_id, number, key, type, parent_id::text as parent_id,
        title, description, status, priority, estimate::text as estimate, due_on::text as due_on,
        completed_at::text as completed_at, created_at::text as created_at, updated_at::text as updated_at, lock_version';

    private const UPDATABLE = ['type', 'parent_id', 'title', 'description', 'status', 'priority', 'estimate', 'due_on', 'completed_at'];

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function __construct(private readonly DatabaseManager $db) {}

    /** Same key as the work.enforce_item_hierarchy() trigger. */
    public function lockHierarchy(string $projectId): void
    {
        $this->connection()->selectOne('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['work.hierarchy:'.$projectId]);
    }

    /** Serialized per project by the counter row lock until commit. */
    public function allocateNumber(string $projectId): int
    {
        return (int) ((array) $this->connection()->selectOne(
            'insert into work.project_counters (organization_id, project_id, next_number)
             values (platform.current_organization_id(), ?::uuid, 2)
             on conflict (organization_id, project_id) do update set next_number = work.project_counters.next_number + 1
             returning next_number - 1 as number',
            [$projectId]
        ))['number'];
    }

    /**
     * @param  array{type: string, parent_id: string|null, title: string, description: string|null, priority: string, estimate: string|null, due_on: string|null}  $values
     */
    public function create(string $projectId, int $number, string $key, array $values, string $createdByMembershipId): string
    {
        return $this->guarded(fn () => (string) ((array) $this->connection()->selectOne(
            'insert into work.items (organization_id, project_id, number, key, type, parent_id, title, description, priority, estimate, due_on, created_by_membership_id)
             values (platform.current_organization_id(), ?::uuid, ?, ?, ?, ?::uuid, ?, ?, ?, ?::numeric, ?::date, ?::uuid)
             returning id::text as id',
            [$projectId, $number, $key, $values['type'], $values['parent_id'], $values['title'], $values['description'],
                $values['priority'], $values['estimate'], $values['due_on'], $createdByMembershipId]
        ))['id']);
    }

    /**
     * @return ItemRow|null
     */
    public function find(string $itemId): ?array
    {
        if (! self::isUuid($itemId)) {
            return null;
        }
        $row = $this->connection()->selectOne('select '.self::COLUMNS.' from work.items where id = ?::uuid', [$itemId]);

        return $row === null ? null : self::row($row);
    }

    /**
     * @return ItemRow|null
     */
    public function findByKey(string $key): ?array
    {
        if (preg_match('/^[A-Z][A-Z0-9]{1,9}-[1-9][0-9]{0,8}$/', $key) !== 1) {
            return null;
        }
        $row = $this->connection()->selectOne('select '.self::COLUMNS.' from work.items where key = ?', [$key]);

        return $row === null ? null : self::row($row);
    }

    /**
     * Keyset page of items of the given projects (null = every project).
     *
     * @param  list<string>|null  $projectIds
     * @param  array{project_id?: string, status?: string, type?: string, priority?: string, label_id?: string, assignee_membership_id?: string, parent_id?: string}  $filters
     * @param  'number'|'priority'|'updated_at'  $sort
     * @param  array{0: string, 1: string}|null  $after  [sort value, id]
     * @return list<ItemRow>
     */
    public function page(?array $projectIds, array $filters, string $sort, ?array $after, int $limit): array
    {
        $where = ['true'];
        $bindings = [];
        if ($projectIds !== null) {
            $where[] = 'i.project_id = any(?::uuid[])';
            $bindings[] = self::pgArray($projectIds);
        }
        foreach (['status', 'type', 'priority'] as $column) {
            if (isset($filters[$column])) {
                $where[] = "i.{$column} = ?";
                $bindings[] = $filters[$column];
            }
        }
        foreach (['project_id', 'parent_id'] as $column) {
            if (isset($filters[$column])) {
                $where[] = "i.{$column} = ?::uuid";
                $bindings[] = $filters[$column];
            }
        }
        if (isset($filters['label_id'])) {
            $where[] = 'exists (select 1 from work.item_labels l where l.item_id = i.id and l.label_id = ?::uuid and l.removed_at is null)';
            $bindings[] = $filters['label_id'];
        }
        if (isset($filters['assignee_membership_id'])) {
            $where[] = 'exists (select 1 from work.item_assignments a where a.item_id = i.id and a.membership_id = ?::uuid and a.removed_at is null)';
            $bindings[] = $filters['assignee_membership_id'];
        }

        // Allowlisted sort expressions only; values travel as bindings.
        [$expression, $direction, $cast] = match ($sort) {
            'priority' => ["array_position(array['urgent','high','medium','low','none'], i.priority)", 'asc', 'integer'],
            'updated_at' => ['i.updated_at', 'desc', 'timestamptz'],
            default => ['i.number', 'asc', 'integer'],
        };
        if ($after !== null) {
            $where[] = "({$expression}, i.id) ".($direction === 'asc' ? '>' : '<')." (?::{$cast}, ?::uuid)";
            array_push($bindings, $after[0], $after[1]);
        }
        $bindings[] = $limit;

        $rows = $this->connection()->select(
            'select '.self::prefixed().' from work.items i where '.implode(' and ', $where)
            ." order by {$expression} {$direction}, i.id {$direction} limit ?",
            $bindings
        );

        return array_values(array_map(fn (object $row) => self::row($row), $rows));
    }

    /** Sort value of an item for building the next cursor. */
    public function sortValue(string $itemId, string $sort): string
    {
        $expression = match ($sort) {
            'priority' => "array_position(array['urgent','high','medium','low','none'], priority)",
            'updated_at' => 'updated_at',
            default => 'number',
        };

        return (string) ((array) $this->connection()->selectOne("select ({$expression})::text as value from work.items where id = ?::uuid", [$itemId]))['value'];
    }

    /**
     * @param  array<string, string|null>  $changes  allowlisted columns
     */
    public function update(string $itemId, int $expectedVersion, array $changes): bool
    {
        if ($changes === [] || array_diff(array_keys($changes), self::UPDATABLE) !== []) {
            throw new \InvalidArgumentException('Only mutable work item columns can be updated.');
        }
        $casts = ['parent_id' => '::uuid', 'estimate' => '::numeric', 'due_on' => '::date', 'completed_at' => '::timestamptz'];
        $assignments = implode(', ', array_map(fn (string $column) => "{$column} = ?".($casts[$column] ?? ''), array_keys($changes)));

        return $this->guarded(fn () => $this->connection()->update(
            "update work.items set {$assignments}, updated_at = now(), lock_version = lock_version + 1
              where id = ?::uuid and lock_version = ?",
            [...array_values($changes), $itemId, $expectedVersion]
        ) === 1);
    }

    /**
     * @param  array<string, mixed>  $changes  sanitized diff
     */
    public function recordActivity(string $itemId, string $type, string $actorMembershipId, array $changes = []): void
    {
        $this->connection()->insert(
            'insert into work.item_events (organization_id, item_id, type, actor_membership_id, changes)
             values (platform.current_organization_id(), ?::uuid, ?, ?::uuid, ?::jsonb)',
            [$itemId, $type, $actorMembershipId, json_encode((object) $changes, JSON_THROW_ON_ERROR)]
        );
    }

    /**
     * @param  array{0: string, 1: string}|null  $after  [occurred_at, id]
     * @return list<array{id: string, type: string, actor_membership_id: string, changes: array<string, mixed>, occurred_at: string}>
     */
    public function activity(string $itemId, ?array $after, int $limit): array
    {
        $bindings = [$itemId];
        $cursor = '';
        if ($after !== null) {
            $cursor = ' and (occurred_at, id) > (?::timestamptz, ?::uuid)';
            array_push($bindings, $after[0], $after[1]);
        }
        $bindings[] = $limit;

        $rows = $this->connection()->select(
            'select id::text as id, type, actor_membership_id::text as actor_membership_id, changes::text as changes, occurred_at::text as occurred_at
               from work.item_events where item_id = ?::uuid'.$cursor.' order by occurred_at, id limit ?',
            $bindings
        );

        return array_values(array_map(function (object $row): array {
            $c = (array) $row;
            $changes = json_decode((string) $c['changes'], true, flags: JSON_THROW_ON_ERROR);

            return [
                'id' => (string) $c['id'],
                'type' => (string) $c['type'],
                'actor_membership_id' => (string) $c['actor_membership_id'],
                'changes' => is_array($changes) ? $changes : [],
                'occurred_at' => (string) $c['occurred_at'],
            ];
        }, $rows));
    }

    public function createLabel(string $projectId, string $name, string $color, string $createdByMembershipId): string
    {
        try {
            return $this->connection()->transaction(fn () => (string) ((array) $this->connection()->selectOne(
                'insert into work.labels (organization_id, project_id, name, color, created_by_membership_id)
                 values (platform.current_organization_id(), ?::uuid, ?, ?, ?::uuid) returning id::text as id',
                [$projectId, $name, $color, $createdByMembershipId]
            ))['id']);
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? null) === '23505') {
                throw WorkRuleViolation::because(WorkRuleViolation::LABEL);
            }

            throw $exception;
        }
    }

    /**
     * @return list<array{id: string, name: string, color: string}>
     */
    public function labelsOf(string $projectId): array
    {
        return array_values(array_map(fn (object $row) => self::label($row), $this->connection()->select(
            'select id::text as id, name, color from work.labels where project_id = ?::uuid order by lower(name), id',
            [$projectId]
        )));
    }

    /**
     * @return list<array{id: string, name: string, color: string}>
     */
    public function labelsOfItem(string $itemId): array
    {
        return array_values(array_map(fn (object $row) => self::label($row), $this->connection()->select(
            'select l.id::text as id, l.name, l.color from work.item_labels il join work.labels l on l.id = il.label_id
              where il.item_id = ?::uuid and il.removed_at is null order by lower(l.name), l.id',
            [$itemId]
        )));
    }

    public function activeLabelCount(string $itemId): int
    {
        return (int) ((array) $this->connection()->selectOne(
            'select count(*) as total from work.item_labels where item_id = ?::uuid and removed_at is null',
            [$itemId]
        ))['total'];
    }

    /** Idempotent; false when the label was already on the item. */
    public function addLabel(string $projectId, string $itemId, string $labelId, string $addedByMembershipId): bool
    {
        return $this->guarded(fn () => $this->connection()->selectOne(
            'insert into work.item_labels (organization_id, project_id, item_id, label_id, added_by_membership_id)
             values (platform.current_organization_id(), ?::uuid, ?::uuid, ?::uuid, ?::uuid)
             on conflict (organization_id, item_id, label_id) where removed_at is null do nothing
             returning id',
            [$projectId, $itemId, $labelId, $addedByMembershipId]
        ) !== null);
    }

    public function removeLabel(string $itemId, string $labelId, string $removedByMembershipId): int
    {
        return $this->connection()->update(
            'update work.item_labels set removed_at = now(), removed_by_membership_id = ?::uuid
              where item_id = ?::uuid and label_id = ?::uuid and removed_at is null',
            [$removedByMembershipId, $itemId, $labelId]
        );
    }

    /** Role of the membership's active assignment, locked until commit. */
    public function lockAssignmentRole(string $itemId, string $membershipId): ?string
    {
        $row = $this->connection()->selectOne(
            'select role from work.item_assignments where item_id = ?::uuid and membership_id = ?::uuid and removed_at is null for update',
            [$itemId, $membershipId]
        );

        return $row === null ? null : (string) ((array) $row)['role'];
    }

    public function isActiveAssignee(string $itemId, string $membershipId): bool
    {
        return $this->connection()->selectOne(
            'select 1 as found from work.item_assignments where item_id = ?::uuid and membership_id = ?::uuid and removed_at is null',
            [$itemId, $membershipId]
        ) !== null;
    }

    /**
     * @return list<string> teams actively linked to the item
     */
    public function teamIdsOf(string $itemId): array
    {
        return array_values(array_map(fn (object $row) => (string) ((array) $row)['id'], $this->connection()->select(
            'select team_id::text as id from work.item_team_links where item_id = ?::uuid and unlinked_at is null order by 1',
            [$itemId]
        )));
    }

    /** Idempotent; false when the person already had an active row. */
    public function assign(string $itemId, string $membershipId, string $role, string $assignedByMembershipId): bool
    {
        return $this->guarded(fn () => $this->connection()->selectOne(
            'insert into work.item_assignments (organization_id, item_id, membership_id, role, assigned_by_membership_id)
             values (platform.current_organization_id(), ?::uuid, ?::uuid, ?, ?::uuid)
             on conflict (organization_id, item_id, membership_id) where removed_at is null do nothing
             returning id',
            [$itemId, $membershipId, $role, $assignedByMembershipId]
        ) !== null);
    }

    /** Row lock on the item: serializes relation changes (labels, teams, assignees) per item. */
    public function lockItem(string $itemId): void
    {
        $this->connection()->selectOne('select 1 from work.items where id = ?::uuid for update', [$itemId]);
    }

    public function labelCount(string $projectId): int
    {
        return (int) ((array) $this->connection()->selectOne('select count(*) as total from work.labels where project_id = ?::uuid', [$projectId]))['total'];
    }

    public function activeTeamLinkCount(string $itemId): int
    {
        return (int) ((array) $this->connection()->selectOne(
            'select count(*) as total from work.item_team_links where item_id = ?::uuid and unlinked_at is null',
            [$itemId]
        ))['total'];
    }

    public function closeAssignment(string $itemId, string $membershipId, string $removedByMembershipId, string $reason): int
    {
        return $this->connection()->update(
            'update work.item_assignments set removed_at = now(), removed_by_membership_id = ?::uuid, removed_reason = ?
              where item_id = ?::uuid and membership_id = ?::uuid and removed_at is null',
            [$removedByMembershipId, $reason, $itemId, $membershipId]
        );
    }

    /** Active owner of the item, locked until commit. */
    public function lockOwner(string $itemId): ?string
    {
        $row = $this->connection()->selectOne(
            "select membership_id::text as id from work.item_assignments where item_id = ?::uuid and role = 'owner' and removed_at is null for update",
            [$itemId]
        );

        return $row === null ? null : (string) ((array) $row)['id'];
    }

    public function closeAllAssignmentsOf(string $membershipId, string $removedByMembershipId): int
    {
        return $this->connection()->update(
            "update work.item_assignments set removed_at = now(), removed_by_membership_id = ?::uuid, removed_reason = 'membership_revoked'
              where membership_id = ?::uuid and removed_at is null",
            [$removedByMembershipId, $membershipId]
        );
    }

    /**
     * @return list<array{membership_id: string, role: string, assigned_at: string}>
     */
    public function assignmentsOf(string $itemId): array
    {
        return array_values(array_map(function (object $row): array {
            $c = (array) $row;

            return ['membership_id' => (string) $c['membership_id'], 'role' => (string) $c['role'], 'assigned_at' => (string) $c['assigned_at']];
        }, $this->connection()->select(
            'select membership_id::text as membership_id, role, assigned_at::text as assigned_at from work.item_assignments
              where item_id = ?::uuid and removed_at is null order by role desc, assigned_at, id',
            [$itemId]
        )));
    }

    public function linkTeam(string $itemId, string $teamId, string $linkedByMembershipId): bool
    {
        return $this->guarded(fn () => $this->connection()->selectOne(
            'insert into work.item_team_links (organization_id, item_id, team_id, linked_by_membership_id)
             values (platform.current_organization_id(), ?::uuid, ?::uuid, ?::uuid)
             on conflict (organization_id, item_id, team_id) where unlinked_at is null do nothing
             returning id',
            [$itemId, $teamId, $linkedByMembershipId]
        ) !== null);
    }

    public function unlinkTeam(string $itemId, string $teamId, string $unlinkedByMembershipId): int
    {
        return $this->connection()->update(
            'update work.item_team_links set unlinked_at = now(), unlinked_by_membership_id = ?::uuid
              where item_id = ?::uuid and team_id = ?::uuid and unlinked_at is null',
            [$unlinkedByMembershipId, $itemId, $teamId]
        );
    }

    public function addDependency(string $projectId, string $blockerId, string $blockedId, string $createdByMembershipId): bool
    {
        return $this->guarded(fn () => $this->connection()->selectOne(
            'insert into work.item_dependencies (organization_id, project_id, blocker_item_id, blocked_item_id, created_by_membership_id)
             values (platform.current_organization_id(), ?::uuid, ?::uuid, ?::uuid, ?::uuid)
             on conflict (organization_id, blocker_item_id, blocked_item_id) where removed_at is null do nothing
             returning id',
            [$projectId, $blockerId, $blockedId, $createdByMembershipId]
        ) !== null);
    }

    public function removeDependency(string $blockerId, string $blockedId, string $removedByMembershipId): int
    {
        return $this->connection()->update(
            'update work.item_dependencies set removed_at = now(), removed_by_membership_id = ?::uuid
              where blocker_item_id = ?::uuid and blocked_item_id = ?::uuid and removed_at is null',
            [$removedByMembershipId, $blockerId, $blockedId]
        );
    }

    /**
     * Active dependencies of an item, both directions (same project only).
     *
     * @return array{blocked_by: list<array{id: string, key: string, status: string}>, blocks: list<array{id: string, key: string, status: string}>}
     */
    public function dependenciesOf(string $itemId): array
    {
        $map = fn (array $rows) => array_values(array_map(function (object $row): array {
            $c = (array) $row;

            return ['id' => (string) $c['id'], 'key' => (string) $c['key'], 'status' => (string) $c['status']];
        }, $rows));

        return [
            'blocked_by' => $map($this->connection()->select(
                'select i.id::text as id, i.key, i.status from work.item_dependencies d join work.items i on i.id = d.blocker_item_id
                  where d.blocked_item_id = ?::uuid and d.removed_at is null order by i.number',
                [$itemId]
            )),
            'blocks' => $map($this->connection()->select(
                'select i.id::text as id, i.key, i.status from work.item_dependencies d join work.items i on i.id = d.blocked_item_id
                  where d.blocker_item_id = ?::uuid and d.removed_at is null order by i.number',
                [$itemId]
            )),
        ];
    }

    public static function isUuid(?string $value): bool
    {
        return $value !== null && preg_match(self::UUID, $value) === 1;
    }

    /**
     * Savepoint around writes guarded by triggers and foreign keys, so a
     * rejected row maps to a domain error without aborting the caller.
     *
     * @template TResult
     *
     * @param  \Closure(): TResult  $operation
     * @return TResult
     */
    public function guarded(\Closure $operation): mixed
    {
        try {
            return $this->connection()->transaction($operation);
        } catch (QueryException $exception) {
            $state = $exception->errorInfo[0] ?? null;
            $message = $exception->getMessage();

            throw match (true) {
                $state === '23514' && str_contains($message, 'project must not be archived') => WorkRuleViolation::because(WorkRuleViolation::ARCHIVED),
                $state === '23505' && str_contains($message, 'item_assignments_one_owner') => WorkVersionConflict::make(),
                $state === '23514' && (str_contains($message, 'work hierarchy: cycle') || str_contains($message, 'items_not_own_parent')) => WorkRuleViolation::because(WorkRuleViolation::HIERARCHY_CYCLE),
                $state === '23514' && str_contains($message, 'maximum depth exceeded') => WorkRuleViolation::because(WorkRuleViolation::DEPTH),
                $state === '23514' && str_contains($message, 'incompatible parent type') => WorkRuleViolation::because(WorkRuleViolation::TYPE),
                $state === '23514' && str_contains($message, 'parent not found'),
                $state === '23503' && str_contains($message, 'items_parent_fk') => WorkRuleViolation::because(WorkRuleViolation::PARENT),
                $state === '23503' && str_contains($message, 'item_labels_label_fk') => WorkRuleViolation::because(WorkRuleViolation::LABEL),
                $state === '23514' && str_contains($message, 'team must be active'),
                $state === '23503' && str_contains($message, 'item_team_links_team_fk') => WorkRuleViolation::because(WorkRuleViolation::TEAM),
                $state === '23514' && str_contains($message, 'work dependencies: cycle'),
                $state === '23514' && str_contains($message, 'item_dependencies_not_self') => WorkRuleViolation::because(WorkRuleViolation::DEPENDENCY_CYCLE),
                $state === '23514' && str_contains($message, 'work dependencies: limit exceeded') => WorkRuleViolation::because(WorkRuleViolation::DEPENDENCY_LIMIT),
                $state === '23503' && str_contains($message, 'item_dependencies_') => WorkRuleViolation::because(WorkRuleViolation::DEPENDENCY),
                default => $exception,
            };
        }
    }

    public function connection(): Connection
    {
        return $this->db->connection();
    }

    /**
     * @param  list<string>  $ids
     */
    public static function pgArray(array $ids): string
    {
        return '{'.implode(',', array_filter($ids, fn (string $id) => self::isUuid($id))).'}';
    }

    /** Listing columns: the description (up to 20 KB) is never read for lists. */
    private static function prefixed(): string
    {
        return 'i.id::text as id, i.project_id::text as project_id, i.number, i.key, i.type, i.parent_id::text as parent_id,
            i.title, null::text as description, i.status, i.priority, i.estimate::text as estimate, i.due_on::text as due_on,
            i.completed_at::text as completed_at, i.created_at::text as created_at, i.updated_at::text as updated_at, i.lock_version';
    }

    /**
     * @return array{id: string, name: string, color: string}
     */
    private static function label(object $row): array
    {
        $c = (array) $row;

        return ['id' => (string) $c['id'], 'name' => (string) $c['name'], 'color' => (string) $c['color']];
    }

    /**
     * @return ItemRow
     */
    private static function row(object $row): array
    {
        $c = (array) $row;
        $nullable = fn (string $key) => $c[$key] === null ? null : (string) $c[$key];

        return [
            'id' => (string) $c['id'],
            'project_id' => (string) $c['project_id'],
            'number' => (int) $c['number'],
            'key' => (string) $c['key'],
            'type' => (string) $c['type'],
            'parent_id' => $nullable('parent_id'),
            'title' => (string) $c['title'],
            'description' => $nullable('description'),
            'status' => (string) $c['status'],
            'priority' => (string) $c['priority'],
            'estimate' => $nullable('estimate'),
            'due_on' => $nullable('due_on'),
            'completed_at' => $nullable('completed_at'),
            'created_at' => (string) $c['created_at'],
            'updated_at' => (string) $c['updated_at'],
            'version' => (int) $c['lock_version'],
        ];
    }
}
