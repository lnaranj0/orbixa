<?php

namespace Modules\Monitoring\Application;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Access\Contracts\Permission;
use Modules\IdentityTenancy\Contracts\CurrentMembership;
use Modules\IdentityTenancy\Contracts\MembershipLookup;
use Modules\Monitoring\Contracts\GrantOperationDenied;
use Modules\Platform\Contracts\Tenancy\TenantContext;
use Modules\Platform\Contracts\Tenancy\TenantContextRequired;

/** Authorization for grant administration (M5). */
final class GrantGuard
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly CurrentMembership $memberships,
        private readonly MembershipLookup $lookup,
        private readonly Gate $gate,
    ) {}

    /** Actor membership id, locked until commit against a concurrent revocation. */
    public function context(Authenticatable $actor): string
    {
        $this->tenant->current() ?? throw TenantContextRequired::forOperation('Managing monitoring grants');
        $membershipId = $this->memberships->idFor($actor) ?? throw GrantOperationDenied::because('No active membership.');
        if (! $this->lookup->lockActive($membershipId)) {
            throw GrantOperationDenied::because('No active membership.');
        }

        return $membershipId;
    }

    public function require(Authenticatable $actor, Permission $permission): void
    {
        if (! $this->allows($actor, $permission)) {
            throw GrantOperationDenied::because("{$permission->value} is required.");
        }
    }

    public function allows(Authenticatable $actor, Permission $permission): bool
    {
        return $this->gate->forUser($actor)->allows($permission->value);
    }
}
