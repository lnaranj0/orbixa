<?php

namespace Modules\Work\Contracts;

use Modules\Platform\Contracts\Modules\ModuleManifest;

final class WorkManifest implements ModuleManifest
{
    public function module(): string
    {
        return 'Work';
    }

    public function ownedTables(): array
    {
        return [
            'work.project_counters',
            'work.items',
            'work.labels',
            'work.item_labels',
            'work.item_events',
        ];
    }
}
