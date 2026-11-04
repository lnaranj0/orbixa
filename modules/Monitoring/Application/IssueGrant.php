<?php

namespace Modules\Monitoring\Application;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Access\Contracts\Authorizer;
use Modules\Access\Contracts\Permission;
use Modules\IdentityTenancy\Contracts\MembershipLookup;
use Modules\Monitoring\Contracts\GrantOperationDenied;
use Modules\Monitoring\Infrastructure\GrantStore;
use Modules\Platform\Contracts\Events\ActorReference;
use Modules\Platform\Contracts\Events\DomainEvent;
use Modules\Platform\Contracts\Events\DomainEventRecorder;
use Modules\Platform\Contracts\Validation\InputRejected;
use Modules\Teams\Contracts\TeamDirectory;

/**
 * Issues a read-only monitoring grant (M5). Needs monitoring.grants.manage
 * (and monitoring.restricted.view for a restricted grant). The viewer must
 * be an active member holding monitoring.view; the target an active member
 * or team of the organization. Any unusable viewer or target gives the same
 * rejection, recorded in the grant history without echoing data.
 */
final class IssueGrant
{
    /** Bounds the work of every monitoring read (review M-1). */
    public const MAX_ACTIVE_PER_VIEWER = 20;

    public function __construct(
        private readonly GrantGuard $guard,
        private readonly GrantStore $grants,
        private readonly MembershipLookup $memberships,
        private readonly Authorizer $authorizer,
        private readonly TeamDirectory $teams,
        private readonly DomainEventRecorder $events,
    ) {}

    /**
     * @param  array{viewer_membership_id: string, scope_type: string, target_membership_id?: string|null, target_team_id?: string|null, include_descendants?: bool, visibility_level?: string, reason: string, starts_at?: string|null, expires_at: string}  $input
     */
    public function handle(Authenticatable $actor, array $input): IssueResult
    {
        $actorMembershipId = $this->guard->context($actor);
        $this->guard->require($actor, Permission::MonitoringGrantsManage);

        $scope = $input['scope_type'];
        if (! in_array($scope, ['user', 'team', 'organization'], true)) {
            throw InputRejected::because('Invalid scope.');
        }
        $visibility = GrantInput::visibility($input['visibility_level'] ?? 'standard');
        if ($visibility === 'restricted') {
            $this->guard->require($actor, Permission::MonitoringRestrictedView);
        }
        $reason = GrantInput::reason($input['reason']);
        [$startsAt, $expiresAt] = GrantInput::validity($input['starts_at'] ?? null, $input['expires_at']);

        $viewer = $input['viewer_membership_id'];
        if ($viewer === $actorMembershipId) {
            throw GrantOperationDenied::because('Nobody can grant monitoring to themselves.');
        }
        $targetMembership = $scope === 'user' ? ($input['target_membership_id'] ?? null) : null;
        $targetTeam = $scope === 'team' ? ($input['target_team_id'] ?? null) : null;

        if (! $this->usable($viewer, $targetMembership, $targetTeam, $scope)) {
            $this->grants->recordEvent(null, 'rejected', $actorMembershipId, null, [
                'scope_type' => $scope,
                'viewer_membership_id' => GrantStore::isUuid($viewer) ? $viewer : 'invalid',
                'target_membership_id' => $targetMembership === null || GrantStore::isUuid($targetMembership) ? $targetMembership : 'invalid',
                'target_team_id' => $targetTeam === null || GrantStore::isUuid($targetTeam) ? $targetTeam : 'invalid',
            ]);
            $this->events->record(
                new DomainEvent('monitoring.grant.rejected', 1, 'membership', $actorMembershipId, ['scope_type' => $scope]),
                ActorReference::human((string) $actor->getAuthIdentifier()),
            );

            return IssueResult::rejected();
        }

        $grant = [
            'viewer_membership_id' => $viewer,
            'scope_type' => $scope,
            'target_membership_id' => $targetMembership,
            'target_team_id' => $targetTeam,
            'include_descendants' => $scope === 'team' && ($input['include_descendants'] ?? false),
            'visibility_level' => $visibility,
            'reason' => $reason,
            'starts_at' => $startsAt,
            'expires_at' => $expiresAt,
        ];
        if ($this->grants->activeCountFor($viewer) >= self::MAX_ACTIVE_PER_VIEWER) {
            throw InputRejected::because('This viewer already has the maximum number of active grants.');
        }
        $grantId = $this->grants->create($grant, $actorMembershipId);

        $summary = array_diff_key($grant, ['reason' => true]);
        $this->grants->recordEvent($grantId, 'created', $actorMembershipId, $reason, $summary);
        $this->events->record(
            new DomainEvent('monitoring.grant.created', 1, 'monitoring_grant', $grantId, $summary),
            ActorReference::human((string) $actor->getAuthIdentifier()),
        );

        return IssueResult::issued($grantId);
    }

    private function usable(string $viewer, ?string $targetMembership, ?string $targetTeam, string $scope): bool
    {
        if (! GrantStore::isUuid($viewer) || ! $this->memberships->lockActive($viewer)
            || ! $this->authorizer->membershipHas($viewer, Permission::MonitoringView)) {
            return false;
        }

        return match ($scope) {
            'user' => GrantStore::isUuid($targetMembership) && $targetMembership !== $viewer && $this->memberships->lockActive((string) $targetMembership),
            'team' => GrantStore::isUuid($targetTeam) && $this->teams->isActive((string) $targetTeam),
            default => true,
        };
    }
}
