<?php

namespace Modules\Work\Presentation\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Access\Contracts\Permission;
use Modules\IdentityTenancy\Contracts\ActiveOrganization;
use Modules\Platform\Contracts\Http\CursorCodec;
use Modules\Platform\Contracts\Http\InvalidCursor;
use Modules\Platform\Contracts\Tenancy\TenantContext;
use Modules\Platform\Contracts\Validation\InputRejected;
use Modules\Work\Application\CreateWorkItem;
use Modules\Work\Application\TransitionWorkItem;
use Modules\Work\Application\WorkGuard;
use Modules\Work\Application\WorkQueries;
use Modules\Work\Contracts\Workflow\TransitionPolicy;
use Modules\Work\Contracts\Workflow\TransitionRule;
use Modules\Work\Contracts\WorkRuleViolation;
use Modules\Work\Contracts\WorkVersionConflict;
use Modules\Work\Domain\ItemRules;
use Modules\Work\Infrastructure\ItemStore;

/** Minimal work screens until 11-design-system (W11); same rules as the API. */
final class WorkPageController
{
    private const PAGE_SIZE = 25;

    private const STATUS_LABELS = [
        'backlog' => 'Backlog', 'ready' => 'Listo', 'active' => 'En curso',
        'review' => 'En revisión', 'done' => 'Hecho', 'canceled' => 'Cancelado',
    ];

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly CursorCodec $cursors,
    ) {}

    public function backlog(Request $request, ActiveOrganization $active, WorkQueries $queries, WorkGuard $guard): View
    {
        $projectId = (string) $request->route('project');
        $resource = 'backlog-page:'.$active->id->value.':'.$projectId;
        $after = $this->after($request, $resource);
        $user = $this->user($request);

        [$project, $page, $canCreate] = $this->tenant->runFor($user, $active->id, function () use ($user, $guard, $queries, $projectId, $after) {
            $project = $guard->project($user, $projectId);

            return [$project, $queries->backlog($user, $project->id, [], 'number', $after, self::PAGE_SIZE + 1), $guard->canCreate($user, $project)];
        });

        [$page, $next] = $this->paginate($page, $resource);

        return view('work::work.backlog', [
            'organizationId' => $active->id->value,
            'project' => $project,
            'items' => $page,
            'nextCursor' => $next,
            'canCreate' => $canCreate,
            'types' => ItemRules::TYPES,
            'priorities' => ItemRules::PRIORITIES,
            'statusLabels' => self::STATUS_LABELS,
        ]);
    }

    public function myWork(Request $request, ActiveOrganization $active, WorkQueries $queries): View
    {
        $resource = 'my-work-page:'.$active->id->value.':'.$this->user($request)->getAuthIdentifier();
        $after = $this->after($request, $resource);
        $user = $this->user($request);

        $page = $this->tenant->runFor($user, $active->id, fn () => $queries->myWork($user, [], 'number', $after, self::PAGE_SIZE + 1));
        [$page, $next] = $this->paginate($page, $resource);

        return view('work::work.mine', [
            'organizationId' => $active->id->value,
            'items' => $page,
            'nextCursor' => $next,
            'statusLabels' => self::STATUS_LABELS,
        ]);
    }

    public function show(Request $request, ActiveOrganization $active, WorkQueries $queries, WorkGuard $guard, TransitionPolicy $workflow): View
    {
        $user = $this->user($request);
        $itemId = (string) $request->route('item');

        [$detail, $activity, $canTransition, $next] = $this->tenant->runFor($user, $active->id, function () use ($user, $guard, $queries, $itemId, $workflow) {
            $detail = $queries->detail($user, $itemId);
            $membershipId = $guard->context($user);
            [$item, $project] = $guard->item($user, $itemId);
            $current = $item['workflow_status_id'] ?? $workflow->statusForCategory($project->id, $item['status'])?->id;

            return [
                $detail,
                $queries->activity($user, $itemId, null, 100),
                $guard->canChange($user, $membershipId, $item, $project, Permission::WorkTransition),
                $current === null ? [] : $workflow->transitionsFrom($project->id, $current),
            ];
        });

        return view('work::work.show', [
            'organizationId' => $active->id->value,
            'detail' => $detail,
            'activity' => $activity,
            'canTransition' => $canTransition,
            'nextStatuses' => array_map(fn (TransitionRule $rule) => ['id' => $rule->to->id, 'name' => $rule->to->name, 'needsReason' => $rule->requiresReason], $next),
            'statusLabels' => self::STATUS_LABELS,
        ]);
    }

    public function store(Request $request, ActiveOrganization $active, CreateWorkItem $create): RedirectResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'in:'.implode(',', ItemRules::TYPES)],
            'title' => ['required', 'string', 'max:200', 'regex:/^[^\p{Cc}\p{Cf}]*$/u'],
            'priority' => ['required', 'string', 'in:'.implode(',', ItemRules::PRIORITIES)],
        ]);
        $user = $this->user($request);
        $projectId = (string) $request->route('project');

        try {
            $itemId = $this->tenant->runFor($user, $active->id, fn () => $create->handle($user, $projectId, [
                'type' => (string) $validated['type'], 'title' => (string) $validated['title'], 'priority' => (string) $validated['priority'],
            ]));
        } catch (WorkRuleViolation|InputRejected $exception) {
            return back()->withInput()->withErrors(['title' => $exception->getMessage()]);
        }

        return redirect()->route('work.show', ['organization' => $active->id->value, 'item' => $itemId])->with('status', 'Elemento creado.');
    }

    public function transition(Request $request, ActiveOrganization $active, TransitionWorkItem $transition): RedirectResponse
    {
        $validated = $request->validate([
            'expected_version' => ['required', 'integer', 'min:0'],
            'to' => ['required', 'string', 'max:64'],
            'reason' => ['nullable', 'string', 'max:500', 'regex:/^[^\p{Cc}\p{Cf}]*$/u'],
        ]);
        $user = $this->user($request);
        $itemId = (string) $request->route('item');

        try {
            $this->tenant->runFor($user, $active->id, fn () => $transition->handle(
                $user, $itemId, (int) $validated['expected_version'], (string) $validated['to'], isset($validated['reason']) ? (string) $validated['reason'] : null,
            ));
        } catch (WorkRuleViolation|WorkVersionConflict|InputRejected $exception) {
            return back()->withErrors(['to' => $exception->getMessage()]);
        }

        return redirect()->route('work.show', ['organization' => $active->id->value, 'item' => $itemId])->with('status', 'Estado actualizado.');
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function after(Request $request, string $resource): ?array
    {
        $cursor = $request->query('cursor');
        if (! is_string($cursor) || $cursor === '') {
            return null;
        }
        $position = $this->cursors->decode($resource, $cursor);
        if (count($position) !== 2 || ! ItemStore::isUuid($position[1]) || ! ctype_digit($position[0])) {
            throw InvalidCursor::make();
        }

        return [$position[0], $position[1]];
    }

    /**
     * @template TItem of array{id: string, number: int}
     *
     * @param  list<TItem>  $page
     * @return array{0: list<TItem>, 1: string|null}
     */
    private function paginate(array $page, string $resource): array
    {
        if (count($page) <= self::PAGE_SIZE) {
            return [$page, null];
        }
        $page = array_slice($page, 0, self::PAGE_SIZE);
        $last = $page[self::PAGE_SIZE - 1];

        return [$page, $this->cursors->encode($resource, [(string) $last['number'], $last['id']])];
    }

    private function user(Request $request): Authenticatable
    {
        return $request->user() ?? abort(401);
    }
}
