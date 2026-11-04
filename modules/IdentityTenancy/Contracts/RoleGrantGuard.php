<?php

namespace Modules\IdentityTenancy\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Whether the actor may invite someone with the given role in the ACTIVE
 * organization (members.invite plus the anti-escalation rules). Implemented
 * by Access; IdentityTenancy never depends on Access internals.
 */
interface RoleGrantGuard
{
    public function canInvite(Authenticatable $inviter, string $roleKey): bool;
}
