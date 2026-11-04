<?php

namespace Modules\Teams\Contracts;

use Modules\Platform\Contracts\Modules\ModuleManifest;

final class TeamsManifest implements ModuleManifest
{
    public function module(): string
    {
        return 'Teams';
    }

    public function ownedTables(): array
    {
        return ['teams.teams', 'teams.team_members', 'teams.team_parent_changes'];
    }
}
