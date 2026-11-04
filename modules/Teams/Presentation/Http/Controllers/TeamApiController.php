<?php

namespace Modules\Teams\Presentation\Http\Controllers;

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
use Modules\Teams\Application\ArchiveTeam;
use Modules\Teams\Application\AssignTeamMember;
use Modules\Teams\Application\CreateTeam;
use Modules\Teams\Application\MoveTeam;
use Modules\Teams\Application\RemoveTeamMember;
use Modules\Teams\Application\TeamVisibility;
use Modules\Teams\Contracts\TeamNotFound;
use Modules\Teams\Infrastructure\TeamStore;

/**
 * JSON API for teams (D10, D15). Lists are keyset pages without totals,
 * limited to the teams the actor may see.
 */
final class TeamApiController
{
    private const IDEMPOTENCY_KEY = '/^[\x21-\x7E]{8,200}$/';

    public function __construct(private readonly TenantContext $tenant) {}

    public function index(Request $request, ActiveOrganization $active, TeamVisibility $visibility, TeamStore $teams, CursorCodec $cursors): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'between:1,100'],
            'cursor' => ['sometimes', 'string', 'max:1024'],
            'status' => ['sometimes', 'string', 'in:active,archived'],
            'parent_team_id' => ['sometimes', 'string', 'uuid'],
        ]);
        $filters = array_filter(
            ['status' => $validated['status'] ?? null, 'parent_team_id' => isset($validated['parent_team_id']) ? strtolower((string) $validated['parent_team_id']) : null],
            fn ($value) => $value !== null,
        );
        $limit = (int) ($validated['limit'] ?? 25);
        $resource = 'teams:'.$active->id->value.':'.http_build_query($filters);
        $after = isset($validated['cursor']) ? $this->position($cursors->decode($resource, (string) $validated['cursor'])) : null;
        $user = $this->user($request);

        $page = $this->tenant->runFor($user, $active->id, fn () => $teams->page($visibility->visibleIds($user), $filters, $after, $limit + 1));

        $next = null;
        if (count($page) > $limit) {
            $page = array_slice($page, 0, $limit);
            $last = $page[$limit - 1];
            $next = $cursors->encode($resource, [$last['name'], $last['id']]);
        }

        return new JsonResponse(['data' => $page, 'next_cursor' => $next]);
    }

    public function show(Request $request, ActiveOrganization $active, TeamVisibility $visibility): JsonResponse
    {
        $user = $this->user($request);
        $teamId = (string) $request->route('team');

        $team = $this->tenant->runFor($user, $active->id, fn () => $visibility->find($user, $teamId)) ?? throw TeamNotFound::make();

        return new JsonResponse(['data' => $team]);
    }

    public function store(Request $request, ActiveOrganization $active, CreateTeam $create, TeamStore $teams, IdempotencyStore $idempotency): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'parent_team_id' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);
        $user = $this->user($request);

        $body = $this->tenant->runFor($user, $active->id, fn () => $this->idempotent($request, $idempotency, 'teams.store', $validated, function () use ($user, $create, $teams, $validated) {
            $parent = isset($validated['parent_team_id']) ? (string) $validated['parent_team_id'] : null;
            $teamId = $create->handle($user, (string) $validated['name'], $parent);

            return ['data' => $teams->page([$teamId], [], null, 1)[0] ?? throw TeamNotFound::make()];
        }));

        return new JsonResponse($body, 201);
    }

    public function move(Request $request, ActiveOrganization $active, MoveTeam $move): Response
    {
        $validated = $request->validate(['parent_team_id' => ['present', 'nullable', 'string', 'max:64']]);
        $user = $this->user($request);
        $teamId = (string) $request->route('team');
        $parent = $validated['parent_team_id'] === null ? null : (string) $validated['parent_team_id'];

        $this->tenant->runFor($user, $active->id, fn () => $move->handle($user, $teamId, $parent));

        return response()->noContent();
    }

    public function archive(Request $request, ActiveOrganization $active, ArchiveTeam $archive): Response
    {
        $user = $this->user($request);
        $teamId = (string) $request->route('team');

        $this->tenant->runFor($user, $active->id, fn () => $archive->handle($user, $teamId));

        return response()->noContent();
    }

    public function addMember(Request $request, ActiveOrganization $active, AssignTeamMember $assign): Response
    {
        $validated = $request->validate([
            'membership_id' => ['required', 'string', 'max:64'],
            'role' => ['sometimes', 'string', 'in:member,lead'],
        ]);
        $user = $this->user($request);
        $teamId = (string) $request->route('team');

        $this->tenant->runFor($user, $active->id, fn () => $assign->handle($user, $teamId, (string) $validated['membership_id'], (string) ($validated['role'] ?? 'member')));

        return response()->noContent();
    }

    public function removeMember(Request $request, ActiveOrganization $active, RemoveTeamMember $remove): Response
    {
        $user = $this->user($request);
        $teamId = (string) $request->route('team');
        $membershipId = (string) $request->route('membership');

        $this->tenant->runFor($user, $active->id, fn () => $remove->handle($user, $teamId, $membershipId));

        return response()->noContent();
    }

    /**
     * @param  list<string>  $position
     * @return list<string>
     */
    private function position(array $position): array
    {
        if (count($position) !== 2 || ! TeamStore::isUuid($position[1])) {
            throw InvalidCursor::make();
        }

        return $position;
    }

    /**
     * Optional Idempotency-Key, scoped to the user and the operation.
     *
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
            'user:'.$this->user($request)->getAuthIdentifier().':'.$operationName,
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
