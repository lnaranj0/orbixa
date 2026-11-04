<?php

namespace Modules\Monitoring\Presentation\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\IdentityTenancy\Contracts\ActiveOrganization;
use Modules\Monitoring\Application\ChangeGrant;
use Modules\Monitoring\Application\GrantQueries;
use Modules\Monitoring\Application\IssueGrant;
use Modules\Monitoring\Application\MonitoringQueries;
use Modules\Monitoring\Application\MonitoringScope;
use Modules\Monitoring\Contracts\GrantRejected;
use Modules\Monitoring\Infrastructure\GrantStore;
use Modules\Platform\Contracts\Http\CursorCodec;
use Modules\Platform\Contracts\Http\InvalidCursor;
use Modules\Platform\Contracts\Tenancy\TenantContext;
use Modules\Work\Contracts\Monitoring\MonitoredItem;

/**
 * Read-only monitoring API and grant administration (M10). Monitoring reads
 * are GET only; every response is computed from the scope of grants valid
 * at that moment, and cursors are bound to the viewer and to that scope.
 *
 * @phpstan-import-type GrantRow from GrantStore
 */
final class MonitoringApiController
{
    private const TEXT = 'regex:/^[^\p{Cc}\p{Cf}]*$/u';

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly CursorCodec $cursors,
    ) {}

    public function overview(Request $request, ActiveOrganization $active, MonitoringQueries $queries): JsonResponse
    {
        $user = $this->user($request);

        return new JsonResponse(['data' => $this->tenant->runFor($user, $active->id, fn () => $queries->overview($user, $queries->scope($user)))]);
    }

    public function items(Request $request, ActiveOrganization $active, MonitoringQueries $queries): JsonResponse
    {
        return $this->listing($request, $active, $queries, 'items', fn (MonitoringScope $scope) => []);
    }

    public function member(Request $request, ActiveOrganization $active, MonitoringQueries $queries): JsonResponse
    {
        $member = (string) $request->route('member');

        return $this->listing($request, $active, $queries, 'member:'.$member, fn (MonitoringScope $scope) => ['assignee_membership_id' => $queries->requireMember($scope, $member)], withCounts: true);
    }

    public function team(Request $request, ActiveOrganization $active, MonitoringQueries $queries): JsonResponse
    {
        $team = (string) $request->route('team');

        return $this->listing($request, $active, $queries, 'team:'.$team, fn (MonitoringScope $scope) => ['team_ids' => [$queries->requireTeam($scope, $team)]], withCounts: true);
    }

    public function item(Request $request, ActiveOrganization $active, MonitoringQueries $queries): JsonResponse
    {
        $user = $this->user($request);
        $itemId = (string) $request->route('item');

        $item = $this->tenant->runFor($user, $active->id, fn () => $queries->item($user, $queries->scope($user), $itemId));

        return new JsonResponse(['data' => self::itemJson($item) + ['description' => $item->description]]);
    }

    public function grants(Request $request, ActiveOrganization $active, GrantQueries $queries): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'between:1,100'],
            'cursor' => ['sometimes', 'string', 'max:1024'],
            'viewer_membership_id' => ['sometimes', 'string', 'uuid'],
            'active' => ['sometimes', 'boolean'],
        ]);
        $viewer = isset($validated['viewer_membership_id']) ? strtolower((string) $validated['viewer_membership_id']) : null;
        $activeOnly = (bool) ($validated['active'] ?? true);
        $limit = (int) ($validated['limit'] ?? 25);
        $resource = 'grants:'.$active->id->value.':'.$viewer.':'.(int) $activeOnly;
        $after = isset($validated['cursor']) ? $this->position($this->cursors->decode($resource, (string) $validated['cursor'])) : null;
        $user = $this->user($request);

        $page = $this->tenant->runFor($user, $active->id, fn () => $queries->page($user, $viewer, $activeOnly, $after, $limit + 1));
        $next = null;
        if (count($page) > $limit) {
            $page = array_slice($page, 0, $limit);
            $last = $page[$limit - 1];
            $next = $this->cursors->encode($resource, [$last['created_at'], $last['id']]);
        }

        return new JsonResponse(['data' => array_map(self::grant(...), $page), 'next_cursor' => $next]);
    }

    public function issue(Request $request, ActiveOrganization $active, IssueGrant $issue, GrantQueries $queries): JsonResponse
    {
        $validated = $request->validate([
            'viewer_membership_id' => ['required', 'string', 'max:64'],
            'scope_type' => ['required', 'string', 'in:user,team,organization'],
            'target_membership_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'target_team_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'include_descendants' => ['sometimes', 'boolean'],
            'visibility_level' => ['sometimes', 'string', 'in:standard,restricted'],
            'reason' => ['required', 'string', 'max:500', self::TEXT],
            'starts_at' => ['sometimes', 'nullable', 'string', 'max:40'],
            'expires_at' => ['required', 'string', 'max:40'],
        ]);
        $user = $this->user($request);

        $result = $this->tenant->runFor($user, $active->id, function () use ($user, $issue, $queries, $validated) {
            /** @var array{viewer_membership_id: string, scope_type: string, target_membership_id?: string|null, target_team_id?: string|null, include_descendants?: bool, visibility_level?: string, reason: string, starts_at?: string|null, expires_at: string} $validated */
            $result = $issue->handle($user, $validated);

            return $result->wasRejected() ? null : $queries->find($user, (string) $result->grantId);
        });

        // The rejection was audited inside the committed transaction above.
        if ($result === null) {
            throw GrantRejected::make();
        }

        return new JsonResponse(['data' => self::grant($result)], 201);
    }

    public function update(Request $request, ActiveOrganization $active, ChangeGrant $change, GrantQueries $queries): JsonResponse
    {
        $validated = $request->validate([
            'expected_version' => ['required', 'integer', 'min:0'],
            'reason' => ['sometimes', 'string', 'max:500', self::TEXT],
            'include_descendants' => ['sometimes', 'boolean'],
            'visibility_level' => ['sometimes', 'string', 'in:standard,restricted'],
            'expires_at' => ['sometimes', 'string', 'max:40'],
        ]);
        /** @var array{reason?: string, include_descendants?: bool, visibility_level?: string, expires_at?: string} $changes */
        $changes = array_diff_key($validated, ['expected_version' => true]);
        $user = $this->user($request);
        $grantId = (string) $request->route('grant');

        $grant = $this->tenant->runFor($user, $active->id, function () use ($user, $change, $queries, $grantId, $validated, $changes) {
            $change->update($user, $grantId, (int) $validated['expected_version'], $changes);

            return $queries->find($user, $grantId);
        });

        return new JsonResponse(['data' => self::grant($grant)]);
    }

    public function revoke(Request $request, ActiveOrganization $active, ChangeGrant $change): JsonResponse
    {
        $validated = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:500', self::TEXT]]);
        $user = $this->user($request);
        $grantId = (string) $request->route('grant');

        $this->tenant->runFor($user, $active->id, fn () => $change->revoke($user, $grantId, isset($validated['reason']) ? (string) $validated['reason'] : null));

        return new JsonResponse(null, 204);
    }

    /**
     * @param  \Closure(MonitoringScope): array{assignee_membership_id?: string, team_ids?: list<string>}  $narrow
     */
    private function listing(Request $request, ActiveOrganization $active, MonitoringQueries $queries, string $view, \Closure $narrow, bool $withCounts = false): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'between:1,100'],
            'cursor' => ['sometimes', 'string', 'max:1024'],
            'status' => ['sometimes', 'string', 'in:backlog,ready,active,review,done,canceled'],
            'type' => ['sometimes', 'string', 'in:initiative,epic,feature,task,bug,chore,incident'],
            'priority' => ['sometimes', 'string', 'in:none,low,medium,high,urgent'],
        ]);
        $filters = array_filter(
            ['status' => $validated['status'] ?? null, 'type' => $validated['type'] ?? null, 'priority' => $validated['priority'] ?? null],
            fn ($value) => $value !== null,
        );
        $limit = (int) ($validated['limit'] ?? 25);
        $user = $this->user($request);

        [$page, $next, $counts] = $this->tenant->runFor($user, $active->id, function () use ($user, $queries, $narrow, $filters, $validated, $limit, $active, $view, $withCounts) {
            $scope = $queries->scope($user);
            $narrowing = $narrow($scope);
            // Bound to the viewer and to the exact grant scope: a revoked or new grant invalidates it.
            $resource = 'monitoring:'.$active->id->value.':'.$view.':'.$scope->fingerprint().':'.http_build_query($filters);
            $after = isset($validated['cursor']) ? $this->position($this->cursors->decode($resource, (string) $validated['cursor'])) : null;

            $page = $queries->items($user, $scope, $narrowing + $filters, $after, $limit + 1);
            $next = null;
            if (count($page) > $limit) {
                $page = array_slice($page, 0, $limit);
                $last = $page[$limit - 1];
                $next = $this->cursors->encode($resource, [$last->updatedAt, $last->id]);
            }

            return [$page, $next, $withCounts ? $queries->overview($user, $scope, $narrowing) : null];
        });

        $body = ['data' => array_map(self::itemJson(...), $page), 'next_cursor' => $next];
        if ($counts !== null) {
            $body['counts'] = $counts;
        }

        return new JsonResponse($body);
    }

    /**
     * @return array<string, mixed>
     */
    private static function itemJson(MonitoredItem $item): array
    {
        return [
            'id' => $item->id, 'key' => $item->key, 'project_id' => $item->projectId, 'type' => $item->type,
            'title' => $item->title, 'status' => $item->status, 'priority' => $item->priority,
            'due_on' => $item->dueOn, 'completed_at' => $item->completedAt, 'updated_at' => $item->updatedAt,
        ];
    }

    /**
     * @param  GrantRow  $grant
     * @return array<string, mixed>
     */
    private static function grant(array $grant): array
    {
        return [
            'id' => $grant['id'], 'viewer_membership_id' => $grant['viewer_membership_id'], 'scope_type' => $grant['scope_type'],
            'target_membership_id' => $grant['target_membership_id'], 'target_team_id' => $grant['target_team_id'],
            'include_descendants' => $grant['include_descendants'], 'visibility_level' => $grant['visibility_level'],
            'reason' => $grant['reason'], 'granted_by_membership_id' => $grant['granted_by_membership_id'],
            'starts_at' => $grant['starts_at'], 'expires_at' => $grant['expires_at'],
            'revoked_at' => $grant['revoked_at'], 'revoked_reason' => $grant['revoked_reason'], 'version' => $grant['version'],
        ];
    }

    /**
     * @param  list<string>  $position
     * @return array{0: string, 1: string}
     */
    private function position(array $position): array
    {
        if (count($position) !== 2 || ! GrantStore::isUuid($position[1]) || strlen($position[0]) > 64) {
            throw InvalidCursor::make();
        }

        return [$position[0], $position[1]];
    }

    private function user(Request $request): Authenticatable
    {
        return $request->user() ?? abort(401);
    }
}
