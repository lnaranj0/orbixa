<?php

namespace Modules\Monitoring\Infrastructure;

use Modules\IdentityTenancy\Contracts\OrganizationLifecycle;
use Modules\Platform\Contracts\Events\ActorReference;
use Modules\Platform\Contracts\Events\DomainEvent;
use Modules\Platform\Contracts\Events\DomainEventRecorder;
use Modules\Platform\Contracts\Tenancy\OrganizationId;

/** M8: revoking a membership closes the grants where it is the viewer or the observed member. */
final class MonitoringLifecycle implements OrganizationLifecycle
{
    public function __construct(
        private readonly GrantStore $grants,
        private readonly DomainEventRecorder $events,
    ) {}

    public function organizationCreated(OrganizationId $organization, string $creatorMembershipId): void {}

    public function membershipJoined(OrganizationId $organization, string $membershipId, string $roleKey, string $invitedByMembershipId): void {}

    public function membershipRevoking(OrganizationId $organization, string $membershipId, string $revokedByMembershipId): void
    {
        foreach ($this->grants->closeForMembership($membershipId, $revokedByMembershipId) as $grantId) {
            $this->grants->recordEvent($grantId, 'membership_revoked', $revokedByMembershipId, null, ['membership_id' => $membershipId]);
            $this->events->record(
                new DomainEvent('monitoring.grant.revoked', 1, 'monitoring_grant', $grantId, ['revoked_reason' => 'membership_revoked']),
                ActorReference::system(),
            );
        }
    }
}
