<?php

namespace Modules\Reporting\Presentation\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\IdentityTenancy\Contracts\ActiveOrganization;
use Modules\Platform\Contracts\Tenancy\TenantContext;
use Modules\Platform\Contracts\Validation\ReportPeriod;
use Modules\Work\Contracts\FlowMetrics;

/** Flow metrics screen (05 P7, P8): the last 30 days over the projects the member sees. */
final class FlowReportPageController
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function __invoke(Request $request, ActiveOrganization $active, FlowMetrics $metrics): View
    {
        $user = $request->user() ?? abort(401);
        $project = $request->query('project_id');
        $project = is_string($project) && $project !== '' ? strtolower($project) : null;
        $period = ReportPeriod::of(null, null);

        $data = $this->tenant->runFor($user, $active->id, fn () => $metrics->forActor($user, $project, $period->from, $period->to));

        return view('reporting::reports.flow', ['organizationId' => $active->id->value, 'period' => $period, 'metrics' => $data]);
    }
}
