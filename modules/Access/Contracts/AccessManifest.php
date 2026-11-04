<?php

namespace Modules\Access\Contracts;

use Modules\Platform\Contracts\Modules\ModuleManifest;

final class AccessManifest implements ModuleManifest
{
    public function module(): string
    {
        return 'Access';
    }

    public function ownedTables(): array
    {
        return ['access.roles', 'access.role_permissions', 'access.membership_roles'];
    }
}
