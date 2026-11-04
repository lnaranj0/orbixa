<?php

namespace Modules\Work\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Flow metrics for a member (05 P7, P8): computed only over items of
 * projects the actor can see (ProjectVisibility), from the typed status
 * history. Canceled items never count as delivered; nothing per person and
 * no estimate sums (D-G). Must run inside a tenant context.
 */
interface FlowMetrics
{
    /**
     * @param  string|null  $projectId  one visible project, or every visible project
     * @param  string  $from  inclusive date (Y-m-d)
     * @param  string  $to  exclusive date (Y-m-d)
     * @return array{throughput: int, lead_time_days: array{count: int, median: float|null, p85: float|null}, cycle_time_days: array{count: int, median: float|null, p85: float|null}, aging_days: array{count: int, median: float|null, p85: float|null}}
     *
     * @throws WorkItemNotFound when $projectId is not visible
     */
    public function forActor(Authenticatable $actor, ?string $projectId, string $from, string $to): array;
}
