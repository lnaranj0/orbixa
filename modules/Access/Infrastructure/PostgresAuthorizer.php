<?php

namespace Modules\Access\Infrastructure;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Access\Contracts\Authorizer;
use Modules\Access\Contracts\Permission;
use Modules\IdentityTenancy\Contracts\CurrentMembership;

final class PostgresAuthorizer implements Authorizer
{
    public function __construct(
        private readonly CurrentMembership $memberships,
        private readonly RoleStore $roles,
    ) {}

    public function can(Authenticatable $actor, Permission $permission): bool
    {
        $membershipId = $this->memberships->idFor($actor);

        return $membershipId !== null && $this->roles->hasPermission($membershipId, $permission);
    }
}
