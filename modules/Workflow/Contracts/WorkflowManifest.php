<?php

namespace Modules\Workflow\Contracts;

use Modules\Platform\Contracts\Modules\ModuleManifest;

final class WorkflowManifest implements ModuleManifest
{
    public function module(): string
    {
        return 'Workflow';
    }

    public function ownedTables(): array
    {
        return ['workflow.workflows', 'workflow.statuses', 'workflow.transitions'];
    }
}
