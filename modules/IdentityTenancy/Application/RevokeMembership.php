<?php

namespace Modules\IdentityTenancy\Application;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\DatabaseManager;
use Modules\IdentityTenancy\Contracts\CurrentMembership;
use Modules\IdentityTenancy\Contracts\MembershipChangeDenied;
use Modules\IdentityTenancy\Contracts\OrganizationLifecycle;
use Modules\Platform\Contracts\Events\ActorReference;
use Modules\Platform\Contracts\Events\DomainEvent;
use Modules\Platform\Contracts\Events\DomainEventRecorder;
use Modules\Platform\Contracts\Tenancy\TenantContext;
use Modules\Platform\Contracts\Tenancy\TenantContextRequired;

/**
 * Spec "Revocación inmediata": the membership becomes revoked in the same
 * transaction in which Access removes its roles (and vetoes revoking the
 * last owner, or an owner by a non-owner). Access ends on the next request
 * because every request re-verifies the membership.
 */
final class RevokeMembership
{
    /**
     * @param  iterable<OrganizationLifecycle>  $lifecycle
     */
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly CurrentMembership $memberships,
        private readonly Gate $gate,
        private readonly DomainEventRecorder $events,
        private readonly DatabaseManager $db,
        private readonly iterable $lifecycle = [],
    ) {}

    public function handle(Authenticatable $actor, string $targetMembershipId): void
    {
        $organization = $this->tenant->current() ?? throw TenantContextRequired::forOperation('Revoking memberships');
        $actorMembershipId = $this->memberships->idFor($actor) ?? throw MembershipChangeDenied::because('No active membership.');

        if ($actorMembershipId === $targetMembershipId) {
            throw MembershipChangeDenied::because('Use the leave flow to end your own membership.');
        }

        if (! $this->gate->forUser($actor)->allows('members.manage')) {
            throw MembershipChangeDenied::because('members.manage is required.');
        }

        $connection = $this->db->connection();

        $connection->transaction(function () use ($connection, $organization, $actor, $actorMembershipId, $targetMembershipId) {
            $target = $connection->selectOne(
                "select id from identity.organization_memberships where id = ?::uuid and status = 'active' for update",
                [$targetMembershipId]
            );

            if ($target === null) {
                throw MembershipChangeDenied::because('Membership not found.');
            }

            foreach ($this->lifecycle as $hook) {
                $hook->membershipRevoking($organization, $targetMembershipId, $actorMembershipId);
            }

            $connection->update(
                "update identity.organization_memberships
                    set status = 'revoked', revoked_at = now(), revoked_by_membership_id = ?::uuid, lock_version = lock_version + 1
                  where id = ?::uuid",
                [$actorMembershipId, $targetMembershipId]
            );

            $this->events->record(
                new DomainEvent('organization.member.revoked', 1, 'membership', $targetMembershipId, [
                    'revoked_by_membership_id' => $actorMembershipId,
                ]),
                ActorReference::human((string) $actor->getAuthIdentifier()),
            );
        });
    }
}
