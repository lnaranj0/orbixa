<?php

namespace Modules\Monitoring\Application;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Access\Contracts\Permission;
use Modules\Monitoring\Contracts\GrantNotFound;
use Modules\Monitoring\Contracts\GrantVersionConflict;
use Modules\Monitoring\Infrastructure\GrantStore;
use Modules\Platform\Contracts\Events\ActorReference;
use Modules\Platform\Contracts\Events\DomainEvent;
use Modules\Platform\Contracts\Events\DomainEventRecorder;
use Modules\Platform\Contracts\Validation\InputRejected;

/**
 * Edits (optimistic) and revokes grants (M5, M9). Viewer, scope and target
 * never change: those need a new grant. Revoked grants are not found.
 */
final class ChangeGrant
{
    public function __construct(
        private readonly GrantGuard $guard,
        private readonly GrantStore $grants,
        private readonly DomainEventRecorder $events,
    ) {}

    /**
     * @param  array{reason?: string, include_descendants?: bool, visibility_level?: string, expires_at?: string}  $changes
     */
    public function update(Authenticatable $actor, string $grantId, int $expectedVersion, array $changes): int
    {
        $actorMembershipId = $this->guard->context($actor);
        $this->guard->require($actor, Permission::MonitoringGrantsManage);
        $grant = $this->grants->find($grantId, forUpdate: true);
        if ($grant === null || $grant['revoked_at'] !== null) {
            throw GrantNotFound::make();
        }
        // An expired grant is never revived by an edit: that needs a new,
        // fully re-validated grant (review M-2).
        if (new \DateTimeImmutable($grant['expires_at']) <= new \DateTimeImmutable('now')) {
            throw InputRejected::because('The grant has expired; issue a new one.');
        }

        $columns = [];
        if (array_key_exists('reason', $changes)) {
            $columns['reason'] = GrantInput::reason($changes['reason']);
        }
        if (array_key_exists('visibility_level', $changes)) {
            $columns['visibility_level'] = GrantInput::visibility($changes['visibility_level']);
            if ($columns['visibility_level'] === 'restricted') {
                $this->guard->require($actor, Permission::MonitoringRestrictedView);
            }
        }
        if (array_key_exists('include_descendants', $changes)) {
            if ($grant['scope_type'] !== 'team') {
                throw InputRejected::because('Only team grants can include subteams.');
            }
            $columns['include_descendants'] = (bool) $changes['include_descendants'];
        }
        if (array_key_exists('expires_at', $changes)) {
            [, $columns['expires_at']] = GrantInput::validity(null, $changes['expires_at']);
            if (new \DateTimeImmutable($columns['expires_at']) <= new \DateTimeImmutable($grant['starts_at'])) {
                throw InputRejected::because('expires_at must be later than starts_at.');
            }
            $this->requireWithinMaximum($grant['starts_at'], $columns['expires_at']);
        }
        if ($columns === []) {
            throw InputRejected::because('Nothing to change.');
        }

        if ($grant['version'] !== $expectedVersion || ! $this->grants->update($grantId, $expectedVersion, $columns)) {
            throw GrantVersionConflict::make();
        }

        $diff = [];
        foreach ($columns as $field => $value) {
            $diff[$field] = $field === 'reason' ? ['changed' => true] : ['from' => $grant[$field], 'to' => $value];
        }
        $this->grants->recordEvent($grantId, 'updated', $actorMembershipId, $columns['reason'] ?? null, $diff);
        $this->events->record(
            new DomainEvent('monitoring.grant.updated', 1, 'monitoring_grant', $grantId, ['fields' => array_keys($columns)]),
            ActorReference::human((string) $actor->getAuthIdentifier()),
        );

        return $expectedVersion + 1;
    }

    public function revoke(Authenticatable $actor, string $grantId, ?string $reason = null): void
    {
        $actorMembershipId = $this->guard->context($actor);
        $this->guard->require($actor, Permission::MonitoringGrantsManage);
        $reason = $reason === null || trim($reason) === '' ? null : GrantInput::reason($reason);

        if (! GrantStore::isUuid($grantId) || ! $this->grants->revoke($grantId, $actorMembershipId, 'revoked')) {
            throw GrantNotFound::make();
        }

        $this->grants->recordEvent($grantId, 'revoked', $actorMembershipId, $reason);
        $this->events->record(
            new DomainEvent('monitoring.grant.revoked', 1, 'monitoring_grant', $grantId, ['revoked_reason' => 'revoked']),
            ActorReference::human((string) $actor->getAuthIdentifier()),
        );
    }

    /** The table CHECK bounds expires_at by starts_at; fail with a clear 422 first. */
    private function requireWithinMaximum(string $startsAt, string $expiresAt): void
    {
        $limit = (new \DateTimeImmutable($startsAt))->modify('+'.GrantInput::MAX_DAYS.' days');
        if (new \DateTimeImmutable($expiresAt) > $limit) {
            throw InputRejected::because('A grant can last at most '.GrantInput::MAX_DAYS.' days.');
        }
    }
}
