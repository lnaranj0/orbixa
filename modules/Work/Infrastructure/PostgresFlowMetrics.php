<?php

namespace Modules\Work\Infrastructure;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Portfolio\Contracts\ProjectVisibility;
use Modules\Work\Contracts\FlowMetrics;
use Modules\Work\Contracts\WorkItemNotFound;

final class PostgresFlowMetrics implements FlowMetrics
{
    public function __construct(
        private readonly ProjectVisibility $visibility,
        private readonly FlowMetricsCalculator $calculator,
    ) {}

    public function forActor(Authenticatable $actor, ?string $projectId, string $from, string $to): array
    {
        if ($projectId !== null) {
            if (! $this->visibility->canView($actor, $projectId)) {
                throw WorkItemNotFound::make();
            }

            return $this->calculator->compute('i.project_id = ?::uuid', [$projectId], $from, $to);
        }

        $accessible = $this->visibility->accessibleProjects($actor);
        if ($accessible->all) {
            return $this->calculator->compute('true', [], $from, $to);
        }
        if ($accessible->ids === []) {
            return FlowMetricsCalculator::empty();
        }

        return $this->calculator->compute('i.project_id = any(?::uuid[])', [ItemStore::pgArray($accessible->ids)], $from, $to);
    }
}
