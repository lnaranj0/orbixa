<?php

namespace Modules\Access\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Deny-by-default permission check for the ACTIVE tenant context. Outside a
 * context, or without an active membership, every check is false.
 */
interface Authorizer
{
    public function can(Authenticatable $actor, Permission $permission): bool;

    /**
     * True when the actor holds the permission at organization level, or a
     * scoped role over the given team/project grants it (D4). Outside a
     * context, or without an active membership, always false.
     */
    public function canWithin(Authenticatable $actor, Permission $permission, Scope $scope): bool;

    /**
     * Organization-level permission of ANOTHER membership of the active
     * organization (e.g. whether an assignee can see a project). Scoped
     * roles are not considered; foreign memberships are invisible under RLS.
     * The caller must verify that the membership is active
     * (MembershipLookup::lockActive) before relying on the answer.
     */
    public function membershipHas(string $membershipId, Permission $permission): bool;
}
