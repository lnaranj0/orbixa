<?php

namespace Modules\Audit\Contracts;

use Modules\Platform\Contracts\Modules\ModuleManifest;

final class AuditManifest implements ModuleManifest
{
    public function module(): string
    {
        return 'Audit';
    }

    public function ownedTables(): array
    {
        // Skeleton: the append-only audit log arrives with its first consumer.
        return [];
    }
}
