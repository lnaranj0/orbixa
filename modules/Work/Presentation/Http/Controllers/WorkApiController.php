<?php

namespace Modules\Work\Presentation\Http\Controllers;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\IdentityTenancy\Contracts\ActiveOrganization;
use Modules\Platform\Contracts\Http\CursorCodec;
use Modules\Platform\Contracts\Http\InvalidCursor;
use Modules\Platform\Contracts\Idempotency\IdempotencyStore;
use Modules\Platform\Contracts\Tenancy\TenantContext;
use Modules\Work\Application\AssignWorkItem;
use Modules\Work\Application\ChangeDependencies;
use Modules\Work\Application\ChangeItemLabels;
use Modules\Work\Application\CreateLabel;
use Modules\Work\Application\CreateWorkItem;
use Modules\Work\Application\LinkItemTeam;
use Modules\Work\Application\TransitionWorkItem;
use Modules\Work\Application\UpdateWorkItem;
use Modules\Work\Application\WorkQueries;
use Modules\Work\Contracts\WorkItemNotFound;
use Modules\Work\Domain\ItemRules;
use Modules\Work\Infrastructure\ItemStore;

/**
 * JSON API for work items (W11). Every action runs in TenantContext::runFor
 * and starts from project visibility; lists are keyset pages without totals.
 *
 * @phpstan-import-type ItemRow from ItemStore
 */
final class WorkApiController
{
    private const IDEMPOTENCY_KEY = '/^[\x21-\x7E]{8,200}$/';

    /** No control or format characters (bidi overrides, zero-width) in single-line text. */
    private const TEXT = 'regex:/^[^\p{Cc}\p{Cf}]*$/u';

    /** Multi-line text: also allows line breaks and tabs. */
    private const MULTILINE = 'regex:/^(?:[^\p{Cc}\p{Cf}]|[\n\r\t])*$/u';

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly CursorCodec $cursors,
    ) {}

    public function backlog(Request $request, ActiveOrganization $active, WorkQueries $queries): JsonResponse
    {
        $projectId = (string) $request->route('project');

        return $this->listing($request, $active, 'backlog:'.$projectId, fn (array $filters, string $sort, ?array $after, int $limit) => $queries->backlog($this->user($request), $projectId, $filters, $sort, $after, $limit), $queries);
    }

    public function myWork(Request $request, ActiveOrganization $active, WorkQueries $queries): JsonResponse
    {
        return $this->listing($request, $active, 'my-work:'.$this->user($request)->getAuthIdentifier(), fn (array $filters, string $sort, ?array $after, int $limit) => $queries->myWork($this->user($request), $filters, $sort, $after, $limit), $queries);
    }

    public function store(Request $request, ActiveOrganization $active, CreateWorkItem $create, WorkQueries $queries, IdempotencyStore $idempotency): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'in:'.implode(',', ItemRules::TYPES)],
            'title' => ['required', 'string', 'max:200', self::TEXT],
            'description' => ['sometimes', 'nullable', 'string', 'max:20000', self::MULTILINE],
            'parent_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'priority' => ['sometimes', 'string', 'in:'.implode(',', ItemRules::PRIORITIES)],
            'estimate' => ['sometimes', 'nullable', 'numeric', 'between:0,9999.99'],
            'due_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);
        $user = $this->user($request);
        $projectId = (string) $request->route('project');

        // Only the id is stored for replays; the detail is rebuilt (and
        // re-authorized, and audited) on every response.
        $detail = $this->tenant->runFor($user, $active->id, function () use ($request, $idempotency, $user, $create, $queries, $projectId, $validated) {
            $stored = $this->idempotent($request, $idempotency, 'work.items.store:'.$projectId, $validated, function () use ($user, $create, $projectId, $validated) {
                /** @var array{type: string, title: string, description?: string|null, parent_id?: string|null, priority?: string, estimate?: int|float|string|null, due_on?: string|null} $validated */
                return ['id' => $create->handle($user, $projectId, $validated)];
            });
            $itemId = $stored['id'] ?? null;

            return $queries->detail($user, is_string($itemId) ? $itemId : '');
        });

        return new JsonResponse(['data' => self::detail($detail)], 201);
    }

    public function show(Request $request, ActiveOrganization $active, WorkQueries $queries): JsonResponse
    {
        $user = $this->user($request);
        $itemId = (string) $request->route('item');

        return new JsonResponse(['data' => self::detail($this->tenant->runFor($user, $active->id, fn () => $queries->detail($user, $itemId)))]);
    }

    public function showByKey(Request $request, ActiveOrganization $active, WorkQueries $queries): JsonResponse
    {
        $user = $this->user($request);
        $key = (string) $request->route('key');

        $detail = $this->tenant->runFor($user, $active->id, function () use ($user, $queries, $key) {
            $itemId = $queries->idForKey($user, $key) ?? throw WorkItemNotFound::make();

            return $queries->detail($user, $itemId);
        });

        return new JsonResponse(['data' => self::detail($detail)]);
    }

    public function update(Request $request, ActiveOrganization $active, UpdateWorkItem $update, WorkQueries $queries): JsonResponse
    {
        $validated = $request->validate([
            'expected_version' => ['required', 'integer', 'min:0'],
            'type' => ['sometimes', 'string', 'in:'.implode(',', ItemRules::TYPES)],
            'title' => ['sometimes', 'string', 'max:200', self::TEXT],
            'description' => ['sometimes', 'nullable', 'string', 'max:20000', self::MULTILINE],
            'parent_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'priority' => ['sometimes', 'string', 'in:'.implode(',', ItemRules::PRIORITIES)],
            'estimate' => ['sometimes', 'nullable', 'numeric', 'between:0,9999.99'],
            'due_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);
        $changes = array_diff_key($validated, ['expected_version' => true]);
        if ($changes === []) {
            abort(422, 'Nothing to change.');
        }
        $user = $this->user($request);
        $itemId = (string) $request->route('item');

        $detail = $this->tenant->runFor($user, $active->id, function () use ($user, $update, $queries, $itemId, $validated, $changes) {
            $update->handle($user, $itemId, (int) $validated['expected_version'], $changes);

            return $queries->detail($user, $itemId);
        });

        return new JsonResponse(['data' => self::detail($detail)]);
    }

    public function transition(Request $request, ActiveOrganization $active, TransitionWorkItem $transition, WorkQueries $queries): JsonResponse
    {
        $validated = $request->validate([
            'expected_version' => ['required', 'integer', 'min:0'],
            'to' => ['required', 'string', 'in:'.implode(',', ItemRules::STATUSES)],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500', self::TEXT],
        ]);
        $user = $this->user($request);
        $itemId = (string) $request->route('item');

        $detail = $this->tenant->runFor($user, $active->id, function () use ($user, $transition, $queries, $itemId, $validated) {
            $transition->handle($user, $itemId, (int) $validated['expected_version'], (string) $validated['to'], isset($validated['reason']) ? (string) $validated['reason'] : null);

            return $queries->detail($user, $itemId);
        });

        return new JsonResponse(['data' => self::detail($detail)]);
    }

    public function activity(Request $request, ActiveOrganization $active, WorkQueries $queries): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'between:1,100'],
            'cursor' => ['sometimes', 'string', 'max:1024'],
        ]);
        $user = $this->user($request);
        $itemId = (string) $request->route('item');
        $limit = (int) ($validated['limit'] ?? 50);
        $resource = 'activity:'.$active->id->value.':'.$itemId;
        $after = isset($validated['cursor']) ? $this->position($this->cursors->decode($resource, (string) $validated['cursor'])) : null;

        $page = $this->tenant->runFor($user, $active->id, fn () => $queries->activity($user, $itemId, $after, $limit + 1));
        $next = null;
        if (count($page) > $limit) {
            $page = array_slice($page, 0, $limit);
            $last = $page[$limit - 1];
            $next = $this->cursors->encode($resource, [$last['occurred_at'], $last['id']]);
        }

        return new JsonResponse(['data' => $page, 'next_cursor' => $next]);
    }

    public function labels(Request $request, ActiveOrganization $active, WorkQueries $queries): JsonResponse
    {
        $user = $this->user($request);
        $projectId = (string) $request->route('project');

        return new JsonResponse(['data' => $this->tenant->runFor($user, $active->id, fn () => $queries->labels($user, $projectId))]);
    }

    public function storeLabel(Request $request, ActiveOrganization $active, CreateLabel $create): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:50', self::TEXT],
            'color' => ['sometimes', 'string', 'regex:/^#[0-9a-f]{6}$/'],
        ]);
        $user = $this->user($request);
        $projectId = (string) $request->route('project');

        $labelId = $this->tenant->runFor($user, $active->id, fn () => $create->handle($user, $projectId, (string) $validated['name'], (string) ($validated['color'] ?? '#64748b')));

        return new JsonResponse(['data' => ['id' => $labelId]], 201);
    }

    public function assign(Request $request, ActiveOrganization $active, AssignWorkItem $assign): Response
    {
        $validated = $request->validate([
            'membership_id' => ['required', 'string', 'max:64'],
            'role' => ['sometimes', 'string', 'in:owner,collaborator'],
        ]);

        return $this->command($request, $active, fn (Authenticatable $user, string $itemId) => $assign->assign($user, $itemId, (string) $validated['membership_id'], (string) ($validated['role'] ?? 'collaborator')));
    }

    public function unassign(Request $request, ActiveOrganization $active, AssignWorkItem $assign): Response
    {
        return $this->command($request, $active, fn (Authenticatable $user, string $itemId) => $assign->unassign($user, $itemId, (string) $request->route('membership')));
    }

    public function addLabel(Request $request, ActiveOrganization $active, ChangeItemLabels $labels): Response
    {
        $validated = $request->validate(['label_id' => ['required', 'string', 'max:64']]);

        return $this->command($request, $active, fn (Authenticatable $user, string $itemId) => $labels->add($user, $itemId, (string) $validated['label_id']));
    }

    public function removeLabel(Request $request, ActiveOrganization $active, ChangeItemLabels $labels): Response
    {
        return $this->command($request, $active, fn (Authenticatable $user, string $itemId) => $labels->remove($user, $itemId, (string) $request->route('label')));
    }

    public function linkTeam(Request $request, ActiveOrganization $active, LinkItemTeam $teams): Response
    {
        $validated = $request->validate(['team_id' => ['required', 'string', 'max:64']]);

        return $this->command($request, $active, fn (Authenticatable $user, string $itemId) => $teams->link($user, $itemId, (string) $validated['team_id']));
    }

    public function unlinkTeam(Request $request, ActiveOrganization $active, LinkItemTeam $teams): Response
    {
        return $this->command($request, $active, fn (Authenticatable $user, string $itemId) => $teams->unlink($user, $itemId, (string) $request->route('team')));
    }

    public function addDependency(Request $request, ActiveOrganization $active, ChangeDependencies $dependencies): Response
    {
        $validated = $request->validate(['blocker_item_id' => ['required', 'string', 'max:64']]);

        return $this->command($request, $active, fn (Authenticatable $user, string $itemId) => $dependencies->add($user, $itemId, (string) $validated['blocker_item_id']));
    }

    public function removeDependency(Request $request, ActiveOrganization $active, ChangeDependencies $dependencies): Response
    {
        return $this->command($request, $active, fn (Authenticatable $user, string $itemId) => $dependencies->remove($user, $itemId, (string) $request->route('blocker')));
    }

    /**
     * @param  Closure(array<string, string>, 'number'|'priority'|'updated_at', array{0: string, 1: string}|null, int): list<ItemRow>  $fetch
     */
    private function listing(Request $request, ActiveOrganization $active, string $listing, Closure $fetch, WorkQueries $queries): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'between:1,100'],
            'cursor' => ['sometimes', 'string', 'max:1024'],
            'sort' => ['sometimes', 'string', 'in:number,priority,updated_at'],
            'status' => ['sometimes', 'string', 'in:'.implode(',', ItemRules::STATUSES)],
            'type' => ['sometimes', 'string', 'in:'.implode(',', ItemRules::TYPES)],
            'priority' => ['sometimes', 'string', 'in:'.implode(',', ItemRules::PRIORITIES)],
            'label_id' => ['sometimes', 'string', 'uuid'],
            'parent_id' => ['sometimes', 'string', 'uuid'],
        ]);
        $filters = [];
        foreach (['status', 'type', 'priority', 'label_id', 'parent_id'] as $field) {
            if (isset($validated[$field])) {
                $filters[$field] = strtolower((string) $validated[$field]);
            }
        }
        /** @var 'number'|'priority'|'updated_at' $sort */
        $sort = (string) ($validated['sort'] ?? 'number');
        $limit = (int) ($validated['limit'] ?? 25);
        $resource = 'items:'.$active->id->value.':'.$listing.':'.$sort.':'.http_build_query($filters);
        $after = isset($validated['cursor']) ? $this->position($this->cursors->decode($resource, (string) $validated['cursor'])) : null;
        $user = $this->user($request);

        [$page, $next] = $this->tenant->runFor($user, $active->id, function () use ($fetch, $filters, $sort, $after, $limit, $queries, $resource) {
            $page = $fetch($filters, $sort, $after, $limit + 1);
            $next = null;
            if (count($page) > $limit) {
                $page = array_slice($page, 0, $limit);
                $last = $page[$limit - 1];
                $next = $this->cursors->encode($resource, [$queries->sortValue($last['id'], $sort), $last['id']]);
            }

            return [$page, $next];
        });

        return new JsonResponse(['data' => array_map(self::summary(...), $page), 'next_cursor' => $next]);
    }

    /**
     * @param  Closure(Authenticatable, string): void  $operation
     */
    private function command(Request $request, ActiveOrganization $active, Closure $operation): Response
    {
        $user = $this->user($request);
        $itemId = (string) $request->route('item');
        $this->tenant->runFor($user, $active->id, fn () => $operation($user, $itemId));

        return response()->noContent();
    }

    /**
     * @param  ItemRow  $item
     * @return array<string, mixed>
     */
    private static function summary(array $item): array
    {
        return [
            'id' => $item['id'], 'key' => $item['key'], 'project_id' => $item['project_id'], 'type' => $item['type'],
            'parent_id' => $item['parent_id'], 'title' => $item['title'], 'status' => $item['status'],
            'priority' => $item['priority'], 'estimate' => $item['estimate'], 'due_on' => $item['due_on'],
            'completed_at' => $item['completed_at'], 'updated_at' => $item['updated_at'], 'version' => $item['version'],
        ];
    }

    /**
     * @param  array{item: ItemRow, project_key: string, labels: list<array{id: string, name: string, color: string}>, assignments: list<array{membership_id: string, role: string, assigned_at: string}>, team_ids: list<string>, dependencies: array{blocked_by: list<array{id: string, key: string, status: string}>, blocks: list<array{id: string, key: string, status: string}>}}  $detail
     * @return array<string, mixed>
     */
    private static function detail(array $detail): array
    {
        return self::summary($detail['item']) + [
            'description' => $detail['item']['description'],
            'created_at' => $detail['item']['created_at'],
            'labels' => $detail['labels'],
            'assignments' => $detail['assignments'],
            'team_ids' => $detail['team_ids'],
            'dependencies' => $detail['dependencies'],
        ];
    }

    /**
     * @param  list<string>  $position
     * @return array{0: string, 1: string}
     */
    private function position(array $position): array
    {
        if (count($position) !== 2 || ! ItemStore::isUuid($position[1]) || strlen($position[0]) > 64) {
            throw InvalidCursor::make();
        }

        return [$position[0], $position[1]];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  Closure(): array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    private function idempotent(Request $request, IdempotencyStore $idempotency, string $operationName, array $input, Closure $operation): array
    {
        $key = $request->header('Idempotency-Key');
        if ($key === null) {
            return $operation();
        }
        if (preg_match(self::IDEMPOTENCY_KEY, $key) !== 1) {
            abort(422, 'Idempotency-Key must be 8-200 visible ASCII characters.');
        }
        ksort($input);

        return $idempotency->remember(
            substr('user:'.$this->user($request)->getAuthIdentifier().':'.$operationName, 0, 100),
            $key,
            hash('sha256', json_encode($input, JSON_THROW_ON_ERROR)),
            $operation,
        );
    }

    private function user(Request $request): Authenticatable
    {
        return $request->user() ?? abort(401);
    }
}
