<?php

namespace Modules\Workflow\Presentation\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\IdentityTenancy\Contracts\ActiveOrganization;
use Modules\Platform\Contracts\Tenancy\TenantContext;
use Modules\Workflow\Application\ConfigureWorkflow;
use Modules\Workflow\Infrastructure\WorkflowStore;

/** Read-only workflow screen until 11-design-system; changes go through the API. */
final class WorkflowPageController
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function __invoke(Request $request, ActiveOrganization $active, ConfigureWorkflow $configure, WorkflowStore $store): View
    {
        $user = $request->user() ?? abort(401);
        $projectId = (string) $request->route('project');

        [$workflow, $statuses, $transitions] = $this->tenant->runFor($user, $active->id, function () use ($user, $configure, $store, $projectId) {
            $workflow = $configure->workflowFor($user, $projectId, forChange: false);

            return $workflow['workflow_id'] === ''
                ? [$workflow, [], []]
                : [$workflow, $store->statuses($workflow['workflow_id']), $store->transitions($workflow['workflow_id'])];
        });

        return view('workflow::workflow.show', [
            'organizationId' => $active->id->value,
            'projectId' => $projectId,
            'version' => $workflow['version'],
            'statuses' => $statuses,
            'names' => array_column($statuses, 'name', 'id'),
            'transitions' => array_values(array_filter($transitions, fn (array $t) => $t['disabled_at'] === null)),
        ]);
    }
}
