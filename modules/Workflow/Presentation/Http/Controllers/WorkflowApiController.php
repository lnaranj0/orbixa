<?php

namespace Modules\Workflow\Presentation\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\IdentityTenancy\Contracts\ActiveOrganization;
use Modules\Platform\Contracts\Tenancy\TenantContext;
use Modules\Workflow\Application\ConfigureWorkflow;
use Modules\Workflow\Infrastructure\WorkflowStore;

/**
 * Project workflow API (05 P3). Reading needs the project to be visible;
 * changes need work.edit over it and the current workflow version.
 */
final class WorkflowApiController
{
    private const NAME = 'regex:/^[^\p{Cc}\p{Cf}]*$/u';

    public function __construct(private readonly TenantContext $tenant) {}

    public function show(Request $request, ActiveOrganization $active, ConfigureWorkflow $configure, WorkflowStore $store): JsonResponse
    {
        $user = $this->user($request);
        $projectId = (string) $request->route('project');

        return new JsonResponse(['data' => $this->tenant->runFor($user, $active->id, function () use ($user, $configure, $store, $projectId) {
            $workflow = $configure->workflowFor($user, $projectId, forChange: false);

            return [
                'id' => $workflow['workflow_id'],
                'version' => $workflow['version'],
                'statuses' => $store->statuses($workflow['workflow_id']),
                'transitions' => $store->transitions($workflow['workflow_id']),
            ];
        })]);
    }

    public function addStatus(Request $request, ActiveOrganization $active, ConfigureWorkflow $configure): JsonResponse
    {
        $v = $request->validate([
            'expected_version' => ['required', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:60', self::NAME],
            'category' => ['required', 'string', 'in:'.implode(',', ConfigureWorkflow::CATEGORIES)],
            'position' => ['required', 'integer', 'between:0,1000'],
            'wip_limit' => ['sometimes', 'nullable', 'integer', 'between:1,1000'],
        ]);
        $id = $this->run($request, $active, fn (Authenticatable $user, string $project) => $configure->addStatus(
            $user, $project, (int) $v['expected_version'], (string) $v['name'], (string) $v['category'], (int) $v['position'],
            isset($v['wip_limit']) ? (int) $v['wip_limit'] : null,
        ));

        return new JsonResponse(['data' => ['id' => $id]], 201);
    }

    public function updateStatus(Request $request, ActiveOrganization $active, ConfigureWorkflow $configure): JsonResponse
    {
        $v = $request->validate([
            'expected_version' => ['required', 'integer', 'min:1'],
            'name' => ['sometimes', 'string', 'max:60', self::NAME],
            'position' => ['sometimes', 'integer', 'between:0,1000'],
            'wip_limit' => ['sometimes', 'nullable', 'integer', 'between:1,1000'],
        ]);
        $changes = [];
        if (isset($v['name'])) {
            $changes['name'] = (string) $v['name'];
        }
        if (isset($v['position'])) {
            $changes['position'] = (int) $v['position'];
        }
        if (array_key_exists('wip_limit', $v)) {
            $changes['wip_limit'] = $v['wip_limit'] === null ? null : (int) $v['wip_limit'];
        }
        $statusId = (string) $request->route('status');
        $this->run($request, $active, fn (Authenticatable $user, string $project) => $configure->updateStatus($user, $project, (int) $v['expected_version'], $statusId, $changes));

        return new JsonResponse(null, 204);
    }

    public function archiveStatus(Request $request, ActiveOrganization $active, ConfigureWorkflow $configure): JsonResponse
    {
        $v = $request->validate(['expected_version' => ['required', 'integer', 'min:1']]);
        $statusId = (string) $request->route('status');
        $this->run($request, $active, fn (Authenticatable $user, string $project) => $configure->archiveStatus($user, $project, (int) $v['expected_version'], $statusId));

        return new JsonResponse(null, 204);
    }

    public function addTransition(Request $request, ActiveOrganization $active, ConfigureWorkflow $configure): JsonResponse
    {
        $v = $request->validate([
            'expected_version' => ['required', 'integer', 'min:1'],
            'from_status_id' => ['required', 'string', 'max:64'],
            'to_status_id' => ['required', 'string', 'max:64'],
            'requires_reason' => ['sometimes', 'boolean'],
            'check_blockers' => ['sometimes', 'boolean'],
            'required_permission' => ['sometimes', 'string', 'in:'.implode(',', ConfigureWorkflow::PERMISSIONS)],
        ]);
        $id = $this->run($request, $active, fn (Authenticatable $user, string $project) => $configure->addTransition(
            $user, $project, (int) $v['expected_version'], (string) $v['from_status_id'], (string) $v['to_status_id'],
            (bool) ($v['requires_reason'] ?? false), (bool) ($v['check_blockers'] ?? false), (string) ($v['required_permission'] ?? 'work.transition'),
        ));

        return new JsonResponse(['data' => ['id' => $id]], 201);
    }

    public function updateTransition(Request $request, ActiveOrganization $active, ConfigureWorkflow $configure): JsonResponse
    {
        $v = $request->validate([
            'expected_version' => ['required', 'integer', 'min:1'],
            'requires_reason' => ['sometimes', 'boolean'],
            'check_blockers' => ['sometimes', 'boolean'],
            'required_permission' => ['sometimes', 'string', 'in:'.implode(',', ConfigureWorkflow::PERMISSIONS)],
        ]);
        $changes = [];
        foreach (['requires_reason', 'check_blockers'] as $flag) {
            if (array_key_exists($flag, $v)) {
                $changes[$flag] = (bool) $v[$flag];
            }
        }
        if (isset($v['required_permission'])) {
            $changes['required_permission'] = (string) $v['required_permission'];
        }
        $transitionId = (string) $request->route('transition');
        $this->run($request, $active, fn (Authenticatable $user, string $project) => $configure->updateTransition($user, $project, (int) $v['expected_version'], $transitionId, $changes));

        return new JsonResponse(null, 204);
    }

    public function disableTransition(Request $request, ActiveOrganization $active, ConfigureWorkflow $configure): JsonResponse
    {
        $v = $request->validate(['expected_version' => ['required', 'integer', 'min:1']]);
        $transitionId = (string) $request->route('transition');
        $this->run($request, $active, fn (Authenticatable $user, string $project) => $configure->updateTransition($user, $project, (int) $v['expected_version'], $transitionId, [], disable: true));

        return new JsonResponse(null, 204);
    }

    /**
     * @template TResult
     *
     * @param  \Closure(Authenticatable, string): TResult  $operation
     * @return TResult
     */
    private function run(Request $request, ActiveOrganization $active, \Closure $operation): mixed
    {
        $user = $this->user($request);
        $project = (string) $request->route('project');

        return $this->tenant->runFor($user, $active->id, fn () => $operation($user, $project));
    }

    private function user(Request $request): Authenticatable
    {
        return $request->user() ?? abort(401);
    }
}
