<?php

namespace Modules\Portfolio\Infrastructure;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Modules\Portfolio\Contracts\ProjectKeyUnavailable;
use Modules\Portfolio\Contracts\ProjectRuleViolation;
use Modules\Portfolio\Contracts\ProjectSummary;

/**
 * Portfolio-owned tables only, always inside the caller's tenant context
 * (RLS scopes them to the active organization). It does NOT apply project
 * visibility: callers go through ProjectVisibility first.
 */
final class ProjectStore
{
    private const COLUMNS = 'id::text as id, key, name, type, status, confidentiality, lock_version, description';

    private const UPDATABLE = ['name', 'description', 'type', 'status', 'confidentiality'];

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function __construct(private readonly DatabaseManager $db) {}

    public function find(string $projectId): ?ProjectSummary
    {
        if (! self::isUuid($projectId)) {
            return null;
        }

        $row = $this->connection()->selectOne('select '.self::COLUMNS.' from portfolio.projects where id = ?::uuid', [$projectId]);

        return $row === null ? null : self::summary($row);
    }

    /**
     * @param  list<string>|null  $ids  null = every project of the organization
     * @return list<ProjectSummary>
     */
    public function list(?array $ids): array
    {
        $rows = $ids === null
            ? $this->connection()->select('select '.self::COLUMNS.' from portfolio.projects order by key')
            : $this->connection()->select('select '.self::COLUMNS.' from portfolio.projects where id = any(?::uuid[]) order by key', [self::pgArray($ids)]);

        return array_values(array_map(fn (object $row) => self::summary($row), $rows));
    }

    /**
     * Keyset page ordered by (key, id). Visibility ($ids, null = all) and the
     * allowlisted filters are applied on every page, never taken from the cursor.
     *
     * @param  list<string>|null  $ids
     * @param  array{status?: string, type?: string, confidentiality?: string}  $filters
     * @param  list<string>|null  $after  [key, id] of the last row of the previous page
     * @return list<ProjectSummary>
     */
    public function page(?array $ids, array $filters, ?array $after, int $limit): array
    {
        $where = ['true'];
        $bindings = [];
        if ($ids !== null) {
            $where[] = 'id = any(?::uuid[])';
            $bindings[] = self::pgArray($ids);
        }
        foreach (['status', 'type', 'confidentiality'] as $column) {
            if (isset($filters[$column])) {
                $where[] = "{$column} = ?";
                $bindings[] = $filters[$column];
            }
        }
        if ($after !== null) {
            $where[] = '(key, id) > (?, ?::uuid)';
            array_push($bindings, $after[0], $after[1]);
        }
        $bindings[] = $limit;

        $rows = $this->connection()->select(
            'select '.self::COLUMNS.' from portfolio.projects where '.implode(' and ', $where).' order by key, id limit ?',
            $bindings
        );

        return array_values(array_map(fn (object $row) => self::summary($row), $rows));
    }

    /**
     * Projects visible through (b) direct membership or (c) a direct link of
     * one of the given active teams to a 'standard' project.
     *
     * @param  list<string>  $teamIds
     * @return list<string>
     */
    public function visibleIdsFor(string $membershipId, array $teamIds): array
    {
        return array_values(array_map(fn (object $row) => (string) ((array) $row)['id'], $this->connection()->select(
            "select pm.project_id::text as id
               from portfolio.project_members pm
              where pm.membership_id = ?::uuid and pm.removed_at is null
             union
             select l.project_id::text
               from portfolio.project_team_links l
               join portfolio.projects p on p.id = l.project_id
              where l.unlinked_at is null and p.confidentiality = 'standard' and l.team_id = any(?::uuid[])",
            [$membershipId, self::pgArray($teamIds)]
        )));
    }

    /** @throws ProjectKeyUnavailable */
    public function create(string $key, string $name, string $type, string $confidentiality, ?string $description, string $createdByMembershipId): string
    {
        try {
            // Savepoint: a duplicate key must not abort the caller's transaction.
            return $this->connection()->transaction(fn () => (string) ((array) $this->connection()->selectOne(
                'insert into portfolio.projects (organization_id, key, name, type, confidentiality, description, created_by_membership_id)
                 values (platform.current_organization_id(), ?, ?, ?, ?, ?, ?::uuid) returning id::text as id',
                [$key, $name, $type, $confidentiality, $description, $createdByMembershipId]
            ))['id']);
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23505' && str_contains($exception->getMessage(), 'projects_key_unique')) {
                throw ProjectKeyUnavailable::make();
            }

            throw $exception;
        }
    }

    /**
     * @param  array<string, string|null>  $changes  column => value (allowlisted by the caller)
     */
    public function update(string $projectId, int $expectedVersion, array $changes): bool
    {
        // Column names are interpolated: never trust the caller's allowlist alone.
        if ($changes === [] || array_diff(array_keys($changes), self::UPDATABLE) !== []) {
            throw new \InvalidArgumentException('Only mutable project columns can be updated.');
        }
        $assignments = implode(', ', array_map(fn (string $column) => "{$column} = ?", array_keys($changes)));

        return $this->connection()->update(
            "update portfolio.projects set {$assignments}, updated_at = now(), lock_version = lock_version + 1
              where id = ?::uuid and lock_version = ?",
            [...array_values($changes), $projectId, $expectedVersion]
        ) === 1;
    }

    public function addMember(string $projectId, string $membershipId, string $role, string $assignedByMembershipId): bool
    {
        return $this->guarded(fn () => $this->connection()->selectOne(
            'insert into portfolio.project_members (organization_id, project_id, membership_id, role, assigned_by_membership_id)
             values (platform.current_organization_id(), ?::uuid, ?::uuid, ?, ?::uuid)
             on conflict (organization_id, project_id, membership_id) where removed_at is null do nothing
             returning id',
            [$projectId, $membershipId, $role, $assignedByMembershipId]
        ) !== null);
    }

    public function removeMember(string $projectId, string $membershipId, string $removedByMembershipId): int
    {
        return $this->connection()->update(
            "update portfolio.project_members set removed_at = now(), removed_by_membership_id = ?::uuid, removed_reason = 'removed'
              where project_id = ?::uuid and membership_id = ?::uuid and removed_at is null",
            [$removedByMembershipId, $projectId, $membershipId]
        );
    }

    /** Role of the active project membership, locked for the rest of the transaction. */
    public function lockMemberRole(string $projectId, string $membershipId): ?string
    {
        $row = $this->connection()->selectOne(
            'select role from portfolio.project_members
              where project_id = ?::uuid and membership_id = ?::uuid and removed_at is null for update',
            [$projectId, $membershipId]
        );

        return $row === null ? null : (string) ((array) $row)['role'];
    }

    public function linkTeam(string $projectId, string $teamId, string $linkedByMembershipId): bool
    {
        return $this->guarded(fn () => $this->connection()->selectOne(
            'insert into portfolio.project_team_links (organization_id, project_id, team_id, linked_by_membership_id)
             values (platform.current_organization_id(), ?::uuid, ?::uuid, ?::uuid)
             on conflict (organization_id, project_id, team_id) where unlinked_at is null do nothing
             returning id',
            [$projectId, $teamId, $linkedByMembershipId]
        ) !== null);
    }

    public function unlinkTeam(string $projectId, string $teamId, string $unlinkedByMembershipId): int
    {
        return $this->connection()->update(
            'update portfolio.project_team_links set unlinked_at = now(), unlinked_by_membership_id = ?::uuid
              where project_id = ?::uuid and team_id = ?::uuid and unlinked_at is null',
            [$unlinkedByMembershipId, $projectId, $teamId]
        );
    }

    public function isActiveMember(string $projectId, string $membershipId, ?string $role = null): bool
    {
        return $this->connection()->selectOne(
            'select 1 as found from portfolio.project_members
              where project_id = ?::uuid and membership_id = ?::uuid and removed_at is null'.($role === null ? '' : ' and role = ?'),
            $role === null ? [$projectId, $membershipId] : [$projectId, $membershipId, $role]
        ) !== null;
    }

    /**
     * @return list<string> projects where the membership is an active direct member
     */
    public function directProjectIdsOf(string $membershipId): array
    {
        return array_values(array_map(fn (object $row) => (string) ((array) $row)['id'], $this->connection()->select(
            'select project_id::text as id from portfolio.project_members where membership_id = ?::uuid and removed_at is null',
            [$membershipId]
        )));
    }

    /** FOR SHARE on the membership's active manager rows (blocks a concurrent demotion until commit). */
    public function lockManagerRolesOf(string $membershipId): void
    {
        $this->connection()->select(
            "select 1 from portfolio.project_members where membership_id = ?::uuid and role = 'manager' and removed_at is null for share",
            [$membershipId]
        );
    }

    public function closeAllForMembership(string $membershipId, string $removedByMembershipId): int
    {
        return $this->connection()->update(
            "update portfolio.project_members set removed_at = now(), removed_by_membership_id = ?::uuid, removed_reason = 'membership_revoked'
              where membership_id = ?::uuid and removed_at is null",
            [$removedByMembershipId, $membershipId]
        );
    }

    /**
     * Savepoint around inserts guarded by triggers and foreign keys, so a
     * rejected row maps to a domain error without aborting the caller.
     *
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

            throw match (true) {
                $state === '23514' && str_contains($exception->getMessage(), 'project must not be archived') => ProjectRuleViolation::because(ProjectRuleViolation::ARCHIVED),
                $state === '23514' && str_contains($exception->getMessage(), 'team must be active'),
                $state === '23503' && str_contains($exception->getMessage(), 'project_team_links_team_fk') => ProjectRuleViolation::because(ProjectRuleViolation::TEAM),
                default => $exception,
            };
        }
    }

    public static function isUuid(?string $value): bool
    {
        return $value !== null && preg_match(self::UUID, $value) === 1;
    }

    /**
     * @param  list<string>  $ids
     */
    private static function pgArray(array $ids): string
    {
        $valid = array_values(array_filter($ids, fn (string $id) => self::isUuid($id)));

        return '{'.implode(',', $valid).'}';
    }

    private static function summary(object $row): ProjectSummary
    {
        $c = (array) $row;

        return new ProjectSummary(
            (string) $c['id'], (string) $c['key'], (string) $c['name'], (string) $c['type'],
            (string) $c['status'], (string) $c['confidentiality'], (int) $c['lock_version'],
            $c['description'] === null ? null : (string) $c['description'],
        );
    }

    private function connection(): Connection
    {
        return $this->db->connection();
    }
}
