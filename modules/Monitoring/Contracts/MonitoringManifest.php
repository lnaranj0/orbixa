<?php

namespace Modules\Monitoring\Contracts;

use Modules\Platform\Contracts\Modules\ModuleManifest;

final class MonitoringManifest implements ModuleManifest
{
    public function module(): string
    {
        return 'Monitoring';
    }

    public function ownedTables(): array
    {
        return ['monitoring.grants', 'monitoring.grant_events'];
    }
}
