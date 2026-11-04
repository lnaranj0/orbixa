<?php

namespace Modules\Planning\Presentation\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\IdentityTenancy\Contracts\ActiveOrganization;
use Modules\Planning\Application\ManageCycles;
use Modules\Planning\Infrastructure\CycleStore;
use Modules\Platform\Contracts\Tenancy\TenantContext;

/** Planning API (05 P9): settings and cycles of a visible project. */
final class PlanningApiController
{
    private const TEXT = 'regex:/^[^\p{Cc}\p{Cf}]*$/u';

    public function __construct(private readonly TenantContext $tenant) {}

    public function show(Request $request, ActiveOrganization $active, ManageCycles $cycles, CycleStore $store): JsonResponse
    {
        $user = $this->user($request);
        $projectId = (string) $request->route('project');

        return new JsonResponse(['data' => $this->tenant->runFor($user, $active->id, function () use ($user, $cycles, $store, $projectId) {
            $project = $cycles->project($user, $projectId);

            return ['mode' => $store->mode($project->id), 'cycles' => $store->ofProject($project->id)];
        })]);
    }

    public function setMode(Request $request, ActiveOrganization $active, ManageCycles $cycles): JsonResponse
    {
        $v = $request->validate(['mode' => ['required', 'string', 'in:'.implode(',', ManageCycles::MODES)]]);
        $user = $this->user($request);
        $projectId = (string) $request->route('project');
        $this->tenant->runFor($user, $active->id, fn () => $cycles->setMode($user, $projectId, (string) $v['mode']));

        return new JsonResponse(null, 204);
    }

    public function create(Request $request, ActiveOrganization $active, ManageCycles $cycles): JsonResponse
    {
        $v = $request->validate([
            'name' => ['required', 'string', 'max:80', self::TEXT],
            'goal' => ['sometimes', 'nullable', 'string', 'max:500', self::TEXT],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d'],
            'capacity' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ]);
        $user = $this->user($request);
        $projectId = (string) $request->route('project');
        $id = $this->tenant->runFor($user, $active->id, fn () => $cycles->create(
            $user, $projectId, (string) $v['name'], isset($v['goal']) ? (string) $v['goal'] : null,
            (string) $v['starts_on'], (string) $v['ends_on'], isset($v['capacity']) ? (string) $v['capacity'] : null,
        ));

        return new JsonResponse(['data' => ['id' => $id]], 201);
    }

    public function cycle(Request $request, ActiveOrganization $active, ManageCycles $cycles, CycleStore $store): JsonResponse
    {
        $user = $this->user($request);
        $cycleId = (string) $request->route('cycle');

        return new JsonResponse(['data' => $this->tenant->runFor($user, $active->id, function () use ($user, $cycles, $store, $cycleId) {
            [$cycle] = $cycles->cycle($user, $cycleId);

            return $cycle + ['items' => $store->items($cycle['id'])];
        })]);
    }

    public function start(Request $request, ActiveOrganization $active, ManageCycles $cycles): JsonResponse
    {
        $user = $this->user($request);
        $cycleId = (string) $request->route('cycle');
        $this->tenant->runFor($user, $active->id, fn () => $cycles->start($user, $cycleId));

        return new JsonResponse(null, 204);
    }

    public function close(Request $request, ActiveOrganization $active, ManageCycles $cycles): JsonResponse
    {
        $v = $request->validate(['carry_to_cycle_id' => ['sometimes', 'nullable', 'string', 'max:64']]);
        $user = $this->user($request);
        $cycleId = (string) $request->route('cycle');
        $counts = $this->tenant->runFor($user, $active->id, fn () => $cycles->close($user, $cycleId, isset($v['carry_to_cycle_id']) ? (string) $v['carry_to_cycle_id'] : null));

        return new JsonResponse(['data' => $counts]);
    }

    public function addItem(Request $request, ActiveOrganization $active, ManageCycles $cycles): JsonResponse
    {
        $v = $request->validate(['item_id' => ['required', 'string', 'max:64'], 'committed' => ['sometimes', 'boolean']]);
        $user = $this->user($request);
        $cycleId = (string) $request->route('cycle');
        $this->tenant->runFor($user, $active->id, fn () => $cycles->addItem($user, $cycleId, (string) $v['item_id'], (bool) ($v['committed'] ?? true)));

        return new JsonResponse(null, 204);
    }

    public function removeItem(Request $request, ActiveOrganization $active, ManageCycles $cycles): JsonResponse
    {
        $user = $this->user($request);
        $cycleId = (string) $request->route('cycle');
        $itemId = (string) $request->route('item');
        $this->tenant->runFor($user, $active->id, fn () => $cycles->removeItem($user, $cycleId, $itemId));

        return new JsonResponse(null, 204);
    }

    private function user(Request $request): Authenticatable
    {
        return $request->user() ?? abort(401);
    }
}
