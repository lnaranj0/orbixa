<?php

namespace Modules\Reporting\Contracts;

use Modules\Platform\Contracts\Modules\ModuleManifest;

/** 05 D-E: Reporting owns no tables; it computes from other modules' contracts. */
final class ReportingManifest implements ModuleManifest
{
    public function module(): string
    {
        return 'Reporting';
    }

    public function ownedTables(): array
    {
        return [];
    }
}
