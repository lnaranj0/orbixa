<?php

namespace Modules\IdentityTenancy\Infrastructure\Membership;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Identity user ids are uuids; anything else (another guard, a test double)
 * can never be a member, so callers fail closed.
 */
final class ActorIdentifier
{
    public static function of(Authenticatable $actor): ?string
    {
        $id = $actor->getAuthIdentifier();

        return is_string($id) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id) === 1
            ? $id
            : null;
    }
}
