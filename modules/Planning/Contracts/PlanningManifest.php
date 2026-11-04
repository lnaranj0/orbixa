<?php

namespace Modules\Planning\Contracts;

use Modules\Platform\Contracts\Modules\ModuleManifest;

final class PlanningManifest implements ModuleManifest
{
    public function module(): string
    {
        return 'Planning';
    }

    public function ownedTables(): array
    {
        return ['planning.project_settings', 'planning.cycles', 'planning.cycle_items'];
    }
}
