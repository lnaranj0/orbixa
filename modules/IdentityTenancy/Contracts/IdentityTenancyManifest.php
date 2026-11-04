<?php

namespace Modules\IdentityTenancy\Contracts;

use Modules\Platform\Contracts\Modules\ModuleManifest;

final class IdentityTenancyManifest implements ModuleManifest
{
    public function module(): string
    {
        return 'IdentityTenancy';
    }

    public function ownedTables(): array
    {
        return [
            'identity.users',
            'identity.password_reset_tokens',
            'identity.organizations',
            'identity.organization_memberships',
            'identity.invitations',
        ];
    }
}
