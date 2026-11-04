<?php

namespace Modules\Portfolio\Contracts;

use Modules\Platform\Contracts\Modules\ModuleManifest;

final class PortfolioManifest implements ModuleManifest
{
    public function module(): string
    {
        return 'Portfolio';
    }

    public function ownedTables(): array
    {
        return ['portfolio.projects', 'portfolio.project_members', 'portfolio.project_team_links'];
    }
}
