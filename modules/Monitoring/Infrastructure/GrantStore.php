<?php

namespace Modules\Monitoring\Infrastructure;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;

/**
 * Monitoring-owned tables only, always inside the caller's tenant context
 * (RLS scopes them to the active organization).
 *
 * @phpstan-type GrantRow array{
 *     id: string, viewer_membership_id: string, scope_type: string, target_membership_id: string|null,
 *     target_team_id: string|null, include_descendants: bool, visibility_level: string, reason: string,
 *     granted_by_membership_id: string, starts_at: string, expires_at: string, revoked_at: string|null,
 *     revoked_reason: string|null, created_at: string, version: int
 * }
 */
final class GrantStore
{
    private const COLUMNS = 'id::text as id, viewer_membership_id::text as viewer_membership_id, scope_type,
        target_membership_id::text as target_membership_id, target_team_id::text as target_team_id, include_descendants,
        visibility_level, reason, granted_by_membership_id::text as granted_by_membership_id, starts_at::text as starts_at,
        expires_at::text as expires_at, revoked_at::text as revoked_at, revoked_reason, created_at::text as created_at, lock_version';

    private const UPDATABLE = ['reason', 'include_descendants', 'visibility_level', 'expires_at'];

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function __construct(private readonly DatabaseManager $db) {}

    /**
     * @param  array{viewer_membership_id: string, scope_type: string, target_membership_id: string|null, target_team_id: string|null, include_descendants: bool, visibility_level: string, reason: string, starts_at: string, expires_at: string}  $grant
     */
    public function create(array $grant, string $grantedByMembershipId): string
    {
        return (string) ((array) $this->connection()->selectOne(
            'insert into monitoring.grants (organization_id, viewer_membership_id, scope_type, target_membership_id, target_team_id,
                 include_descendants, visibility_level, reason, granted_by_membership_id, starts_at, expires_at)
             values (platform.current_organization_id(), ?::uuid, ?, ?::uuid, ?::uuid, ?, ?, ?, ?::uuid, ?::timestamptz, ?::timestamptz)
             returning id::text as id',
            [$grant['viewer_membership_id'], $grant['scope_type'], $grant['target_membership_id'], $grant['target_team_id'],
                $grant['include_descendants'], $grant['visibility_level'], $grant['reason'], $grantedByMembershipId,
                $grant['starts_at'], $grant['expires_at']]
        ))['id'];
    }

    /**
     * @return GrantRow|null
     */
    public function find(string $grantId, bool $forUpdate = false): ?array
    {
        if (! self::isUuid($grantId)) {
            return null;
        }
        $row = $this->connection()->selectOne(
            'select '.self::COLUMNS.' from monitoring.grants where id = ?::uuid'.($forUpdate ? ' for update' : ''),
            [$grantId]
        );

        return $row === null ? null : self::row($row);
    }

    /**
     * @param  array<string, string|bool>  $changes  allowlisted columns
     */
    public function update(string $grantId, int $expectedVersion, array $changes): bool
    {
        if ($changes === [] || array_diff(array_keys($changes), self::UPDATABLE) !== []) {
            throw new \InvalidArgumentException('Only mutable grant columns can be updated.');
        }
        $casts = ['expires_at' => '::timestamptz'];
        $assignments = implode(', ', array_map(fn (string $column) => "{$column} = ?".($casts[$column] ?? ''), array_keys($changes)));

        return $this->connection()->update(
            "update monitoring.grants set {$assignments}, updated_at = now(), lock_version = lock_version + 1
              where id = ?::uuid and lock_version = ? and revoked_at is null",
            [...array_values($changes), $grantId, $expectedVersion]
        ) === 1;
    }

    public function revoke(string $grantId, string $revokedByMembershipId, string $reason): bool
    {
        return $this->connection()->update(
            'update monitoring.grants set revoked_at = now(), revoked_by_membership_id = ?::uuid, revoked_reason = ?,
                    updated_at = now(), lock_version = lock_version + 1
              where id = ?::uuid and revoked_at is null',
            [$revokedByMembershipId, $reason, $grantId]
        ) === 1;
    }

    /**
     * Closes every active grant where the membership is the viewer or the
     * observed member.
     *
     * @return list<string> closed grant ids
     */
    public function closeForMembership(string $membershipId, string $revokedByMembershipId): array
    {
        return array_values(array_map(fn (object $row) => (string) ((array) $row)['id'], $this->connection()->select(
            "update monitoring.grants set revoked_at = now(), revoked_by_membership_id = ?::uuid, revoked_reason = 'membership_revoked',
                    updated_at = now(), lock_version = lock_version + 1
              where revoked_at is null and (viewer_membership_id = ?::uuid or target_membership_id = ?::uuid)
             returning id::text as id",
            [$revokedByMembershipId, $membershipId, $membershipId]
        )));
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function recordEvent(?string $grantId, string $type, string $actorMembershipId, ?string $reason, array $changes = []): void
    {
        $this->connection()->insert(
            'insert into monitoring.grant_events (organization_id, grant_id, type, actor_membership_id, reason, changes)
             values (platform.current_organization_id(), ?::uuid, ?, ?::uuid, ?, ?::jsonb)',
            [$grantId, $type, $actorMembershipId, $reason, json_encode((object) $changes, JSON_THROW_ON_ERROR)]
        );
    }

    /**
     * @param  array{0: string, 1: string}|null  $after  [created_at, id]
     * @return list<GrantRow>
     */
    public function page(?string $viewerMembershipId, bool $activeOnly, ?array $after, int $limit): array
    {
        $where = ['true'];
        $bindings = [];
        if ($viewerMembershipId !== null) {
            $where[] = 'viewer_membership_id = ?::uuid';
            $bindings[] = $viewerMembershipId;
        }
        if ($activeOnly) {
            $where[] = 'revoked_at is null and expires_at > now()';
        }
        if ($after !== null) {
            $where[] = '(created_at, id) < (?::timestamptz, ?::uuid)';
            array_push($bindings, $after[0], $after[1]);
        }
        $bindings[] = $limit;

        return array_values(array_map(fn (object $row) => self::row($row), $this->connection()->select(
            'select '.self::COLUMNS.' from monitoring.grants where '.implode(' and ', $where).' order by created_at desc, id desc limit ?',
            $bindings
        )));
    }

    /**
     * Grants of the viewer valid right now (M8): not revoked, started and
     * not expired. Evaluated on every read, never cached.
     *
     * @return list<GrantRow>
     */
    public function validFor(string $viewerMembershipId): array
    {
        return array_values(array_map(fn (object $row) => self::row($row), $this->connection()->select(
            'select '.self::COLUMNS.' from monitoring.grants
              where viewer_membership_id = ?::uuid and revoked_at is null and starts_at <= now() and expires_at > now()
              order by id',
            [$viewerMembershipId]
        )));
    }

    public static function isUuid(?string $value): bool
    {
        return $value !== null && preg_match(self::UUID, $value) === 1;
    }

    private function connection(): Connection
    {
        return $this->db->connection();
    }

    /**
     * @return GrantRow
     */
    private static function row(object $row): array
    {
        $c = (array) $row;
        $nullable = fn (string $key) => $c[$key] === null ? null : (string) $c[$key];

        return [
            'id' => (string) $c['id'],
            'viewer_membership_id' => (string) $c['viewer_membership_id'],
            'scope_type' => (string) $c['scope_type'],
            'target_membership_id' => $nullable('target_membership_id'),
            'target_team_id' => $nullable('target_team_id'),
            'include_descendants' => (bool) $c['include_descendants'],
            'visibility_level' => (string) $c['visibility_level'],
            'reason' => (string) $c['reason'],
            'granted_by_membership_id' => (string) $c['granted_by_membership_id'],
            'starts_at' => (string) $c['starts_at'],
            'expires_at' => (string) $c['expires_at'],
            'revoked_at' => $nullable('revoked_at'),
            'revoked_reason' => $nullable('revoked_reason'),
            'created_at' => (string) $c['created_at'],
            'version' => (int) $c['lock_version'],
        ];
    }
}
