<?php

namespace Modules\Teams\Infrastructure;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Modules\Platform\Contracts\Tenancy\OrganizationId;
use Modules\Teams\Contracts\HierarchyViolation;

/**
 * Teams-owned tables only; every query runs inside the caller's tenant
 * context, so RLS scopes it to the active organization.
 */
final class TeamStore
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function __construct(private readonly DatabaseManager $db) {}

    /** Same key as the teams.enforce_hierarchy() trigger. */
    public function lockHierarchy(OrganizationId $organization): void
    {
        $this->connection()->selectOne('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['teams.hierarchy:'.$organization->value]);
    }

    /**
     * @return array{id: string, status: string, parent_team_id: string|null}|null
     */
    public function find(string $teamId, bool $forUpdate = false): ?array
    {
        if (! self::isUuid($teamId)) {
            return null;
        }

        $row = $this->connection()->selectOne(
            'select id::text as id, status, parent_team_id::text as parent_team_id from teams.teams where id = ?::uuid'.($forUpdate ? ' for update' : ''),
            [$teamId]
        );

        if ($row === null) {
            return null;
        }

        $columns = (array) $row;

        return [
            'id' => (string) $columns['id'],
            'status' => (string) $columns['status'],
            'parent_team_id' => $columns['parent_team_id'] === null ? null : (string) $columns['parent_team_id'],
        ];
    }

    public function create(string $name, ?string $parentTeamId, string $createdByMembershipId): string
    {
        return $this->guarded(fn () => (string) ((array) $this->connection()->selectOne(
            'insert into teams.teams (organization_id, name, parent_team_id, created_by_membership_id)
             values (platform.current_organization_id(), ?, ?::uuid, ?::uuid) returning id::text as id',
            [$name, $parentTeamId, $createdByMembershipId]
        ))['id']);
    }

    public function move(string $teamId, ?string $oldParentId, ?string $newParentId, string $changedByMembershipId): void
    {
        $this->guarded(function () use ($teamId, $oldParentId, $newParentId, $changedByMembershipId) {
            $this->connection()->update(
                'update teams.teams set parent_team_id = ?::uuid, updated_at = now(), lock_version = lock_version + 1 where id = ?::uuid',
                [$newParentId, $teamId]
            );
            $this->connection()->insert(
                'insert into teams.team_parent_changes (organization_id, team_id, old_parent_team_id, new_parent_team_id, changed_by_membership_id)
                 values (platform.current_organization_id(), ?::uuid, ?::uuid, ?::uuid, ?::uuid)',
                [$teamId, $oldParentId, $newParentId, $changedByMembershipId]
            );
        });
    }

    public function archive(string $teamId): void
    {
        $this->guarded(fn () => $this->connection()->update(
            "update teams.teams set status = 'archived', archived_at = now(), updated_at = now(), lock_version = lock_version + 1 where id = ?::uuid",
            [$teamId]
        ));
    }

    /** Idempotent: returns false when the person was already an active member. */
    public function addMember(string $teamId, string $membershipId, string $role, string $assignedByMembershipId): bool
    {
        return $this->guarded(fn () => $this->connection()->selectOne(
            'insert into teams.team_members (organization_id, team_id, membership_id, role, assigned_by_membership_id)
             values (platform.current_organization_id(), ?::uuid, ?::uuid, ?, ?::uuid)
             on conflict (organization_id, team_id, membership_id) where removed_at is null do nothing
             returning id',
            [$teamId, $membershipId, $role, $assignedByMembershipId]
        ) !== null);
    }

    public function removeMember(string $teamId, string $membershipId, string $removedByMembershipId, string $reason): int
    {
        return $this->connection()->update(
            'update teams.team_members set removed_at = now(), removed_by_membership_id = ?::uuid, removed_reason = ?
              where team_id = ?::uuid and membership_id = ?::uuid and removed_at is null',
            [$removedByMembershipId, $reason, $teamId, $membershipId]
        );
    }

    /** Role of the active team membership, locked for the rest of the transaction. */
    public function lockMemberRole(string $teamId, string $membershipId): ?string
    {
        $row = $this->connection()->selectOne(
            'select role from teams.team_members where team_id = ?::uuid and membership_id = ?::uuid and removed_at is null for update',
            [$teamId, $membershipId]
        );

        return $row === null ? null : (string) ((array) $row)['role'];
    }

    public function closeAllForMembership(string $membershipId, string $removedByMembershipId): int
    {
        return $this->connection()->update(
            "update teams.team_members set removed_at = now(), removed_by_membership_id = ?::uuid, removed_reason = 'membership_revoked'
              where membership_id = ?::uuid and removed_at is null",
            [$removedByMembershipId, $membershipId]
        );
    }

    public function isActiveMember(string $teamId, string $membershipId): bool
    {
        return $this->connection()->selectOne(
            'select 1 as found from teams.team_members where team_id = ?::uuid and membership_id = ?::uuid and removed_at is null',
            [$teamId, $membershipId]
        ) !== null;
    }

    /**
     * @return list<string>
     */
    public function activeTeamIdsOf(string $membershipId): array
    {
        return $this->ids(
            "select tm.team_id::text as id from teams.team_members tm join teams.teams t on t.id = tm.team_id
              where tm.membership_id = ?::uuid and tm.removed_at is null and t.status = 'active' order by 1",
            [$membershipId]
        );
    }

    /**
     * @return list<string>
     */
    public function descendantsOf(string $teamId): array
    {
        return $this->ids(
            'with recursive subtree(id, level) as (
                 select t.id, 1 from teams.teams t where t.parent_team_id = ?::uuid
                 union all
                 select t.id, s.level + 1 from teams.teams t join subtree s on t.parent_team_id = s.id where s.level < 5
             )
             select id::text as id from subtree order by 1',
            [$teamId]
        );
    }

    public static function isUuid(?string $value): bool
    {
        return $value !== null && preg_match(self::UUID, $value) === 1;
    }

    /**
     * @param  list<string>  $bindings
     * @return list<string>
     */
    private function ids(string $sql, array $bindings): array
    {
        return array_values(array_map(fn (object $row) => (string) ((array) $row)['id'], $this->connection()->select($sql, $bindings)));
    }

    /**
     * Translates database invariant violations (triggers, FKs) into domain
     * errors; a foreign parent is reported exactly like a missing one.
     *
     * @template T
     *
     * @param  \Closure(): T  $operation
     * @return T
     */
    private function guarded(\Closure $operation): mixed
    {
        try {
            return $this->connection()->transaction($operation);
        } catch (QueryException $exception) {
            $state = $exception->errorInfo[0] ?? null;
            $message = $exception->getMessage();

            throw match (true) {
                $state === '23514' && str_contains($message, 'cycle'), $state === '23514' && str_contains($message, 'teams_not_own_parent') => HierarchyViolation::because(HierarchyViolation::CYCLE),
                $state === '23514' && str_contains($message, 'maximum depth') => HierarchyViolation::because(HierarchyViolation::DEPTH),
                $state === '23514' && str_contains($message, 'active children') => HierarchyViolation::because(HierarchyViolation::ACTIVE_CHILDREN),
                $state === '23514' && str_contains($message, 'team must be active') => HierarchyViolation::because(HierarchyViolation::ARCHIVED),
                $state === '23514' && str_contains($message, 'parent must be an active team'), $state === '23503' => HierarchyViolation::because(HierarchyViolation::PARENT),
                default => $exception,
            };
        }
    }

    private function connection(): Connection
    {
        return $this->db->connection();
    }
}
