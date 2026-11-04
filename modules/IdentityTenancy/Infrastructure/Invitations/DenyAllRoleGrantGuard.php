<?php

namespace Modules\IdentityTenancy\Infrastructure\Invitations;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\IdentityTenancy\Contracts\RoleGrantGuard;

/** Fail-closed default until the Access module binds the real guard. */
final class DenyAllRoleGrantGuard implements RoleGrantGuard
{
    public function canInvite(Authenticatable $inviter, string $roleKey): bool
    {
        return false;
    }
}
