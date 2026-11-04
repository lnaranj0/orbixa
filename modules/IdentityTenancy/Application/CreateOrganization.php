<?php

namespace Modules\IdentityTenancy\Application;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Modules\IdentityTenancy\Infrastructure\Membership\ActorIdentifier;
use Modules\Platform\Contracts\Events\ActorReference;
use Modules\Platform\Contracts\Events\DomainEvent;
use Modules\Platform\Contracts\Events\DomainEventRecorder;
use Modules\Platform\Contracts\Tenancy\OrganizationId;
use Modules\Platform\Contracts\Tenancy\TenantAccessDenied;
use Modules\Platform\Contracts\Tenancy\TenantContext;

/**
 * Spec "Crear organizaciones": the server generates the id, the creator gets
 * an active membership and organization.created is recorded, all in one
 * transaction (TenantContext rolls back if the membership is missing).
 * The org_owner role assignment is added by the Access module (increment 4).
 */
final class CreateOrganization
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly DomainEventRecorder $events,
        private readonly DatabaseManager $db,
    ) {}

    public function handle(Authenticatable $actor, string $name): OrganizationId
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 120) {
            throw new InvalidArgumentException('Organization name must be 1-120 characters.');
        }

        $userId = ActorIdentifier::of($actor) ?? throw TenantAccessDenied::forActor();

        return $this->tenant->runForNewOrganization($actor, function (OrganizationId $organization) use ($userId, $name) {
            $connection = $this->db->connection();

            $connection->insert(
                'insert into identity.organizations (id, organization_id, name, created_by_user_id) values (?, ?, ?, ?)',
                [$organization->value, $organization->value, $name, $userId]
            );

            $membershipId = $connection->selectOne(
                'insert into identity.organization_memberships (organization_id, user_id)
                 values (platform.current_organization_id(), ?) returning id::text as id',
                [$userId]
            )->id;

            $this->events->record(
                new DomainEvent('organization.created', 1, 'organization', $organization->value, [
                    'created_by_membership_id' => $membershipId,
                ]),
                ActorReference::human($userId),
            );

            return $organization;
        });
    }
}
