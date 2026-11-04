<?php

namespace Modules\Access\Infrastructure;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Access\Contracts\Authorizer;
use Modules\Access\Contracts\Permission;
use Modules\Access\Contracts\Scope;
use Modules\Access\Contracts\ScopedRoleProvider;
use Modules\Access\Domain\ScopedRoleTemplates;
use Modules\IdentityTenancy\Contracts\CurrentMembership;

final class PostgresAuthorizer implements Authorizer
{
    /**
     * @param  iterable<ScopedRoleProvider>  $scopedRoles
     */
    public function __construct(
        private readonly CurrentMembership $memberships,
        private readonly RoleStore $roles,
        private readonly iterable $scopedRoles = [],
    ) {}

    public function can(Authenticatable $actor, Permission $permission): bool
    {
        $membershipId = $this->memberships->idFor($actor);

        return $membershipId !== null && $this->roles->hasPermission($membershipId, $permission);
    }

    public function membershipHas(string $membershipId, Permission $permission): bool
    {
        return $this->roles->hasPermission($membershipId, $permission);
    }

    public function canWithin(Authenticatable $actor, Permission $permission, Scope $scope): bool
    {
        $membershipId = $this->memberships->idFor($actor);

        if ($membershipId === null) {
            return false;
        }

        if ($this->roles->hasPermission($membershipId, $permission)) {
            return true;
        }

        foreach ($this->scopedRoles as $provider) {
            if (ScopedRoleTemplates::grants($provider->scopedRolesOf($membershipId, $scope), $permission)) {
                return true;
            }
        }

        return false;
    }
}
