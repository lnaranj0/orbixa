<?php

namespace Modules\Planning\Presentation\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\IdentityTenancy\Contracts\ActiveOrganization;
use Modules\Planning\Application\ManageCycles;
use Modules\Planning\Infrastructure\CycleStore;
use Modules\Platform\Contracts\Tenancy\TenantContext;

/** Read-only planning screen until 11-design-system; changes go through the API. */
final class PlanningPageController
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function __invoke(Request $request, ActiveOrganization $active, ManageCycles $cycles, CycleStore $store): View
    {
        $user = $request->user() ?? abort(401);
        $projectId = (string) $request->route('project');

        [$mode, $list] = $this->tenant->runFor($user, $active->id, function () use ($user, $cycles, $store, $projectId) {
            $project = $cycles->project($user, $projectId);

            return [$store->mode($project->id), $store->ofProject($project->id)];
        });

        return view('planning::planning.show', ['organizationId' => $active->id->value, 'mode' => $mode, 'cycles' => $list]);
    }
}
