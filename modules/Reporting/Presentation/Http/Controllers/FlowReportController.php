<?php

namespace Modules\Reporting\Presentation\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\IdentityTenancy\Contracts\ActiveOrganization;
use Modules\Platform\Contracts\Tenancy\TenantContext;
use Modules\Platform\Contracts\Validation\ReportPeriod;
use Modules\Work\Contracts\FlowMetrics;

/**
 * GET /reports/flow (05 P7, P8): flow metrics over the projects the member
 * can see, optionally one visible project. Never per person.
 */
final class FlowReportController
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function __invoke(Request $request, ActiveOrganization $active, FlowMetrics $metrics): JsonResponse
    {
        $v = $request->validate([
            'project_id' => ['sometimes', 'string', 'uuid'],
            'from' => ['sometimes', 'string', 'max:10'],
            'to' => ['sometimes', 'string', 'max:10'],
        ]);
        $period = ReportPeriod::of($v['from'] ?? null, $v['to'] ?? null);
        $user = $this->user($request);
        $project = isset($v['project_id']) ? strtolower((string) $v['project_id']) : null;

        $data = $this->tenant->runFor($user, $active->id, fn () => $metrics->forActor($user, $project, $period->from, $period->to));

        return new JsonResponse(['data' => ['period' => ['from' => $period->from, 'to' => $period->to]] + $data]);
    }

    private function user(Request $request): Authenticatable
    {
        return $request->user() ?? abort(401);
    }
}
