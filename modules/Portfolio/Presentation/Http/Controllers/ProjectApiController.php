<?php

namespace Modules\Portfolio\Presentation\Http\Controllers;

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
use Modules\Portfolio\Application\AddProjectMember;
use Modules\Portfolio\Application\CreateProject;
use Modules\Portfolio\Application\LinkProjectTeam;
use Modules\Portfolio\Application\RemoveProjectMember;
use Modules\Portfolio\Application\UnlinkProjectTeam;
use Modules\Portfolio\Application\UpdateProject;
use Modules\Portfolio\Contracts\ProjectDirectory;
use Modules\Portfolio\Contracts\ProjectNotFound;
use Modules\Portfolio\Contracts\ProjectSummary;
use Modules\Portfolio\Contracts\ProjectVisibility;
use Modules\Portfolio\Infrastructure\ProjectStore;

/**
 * JSON API for projects (D10). Every action runs in TenantContext::runFor
 * and goes through ProjectVisibility; lists are keyset pages without totals.
 */
final class ProjectApiController
{
    private const IDEMPOTENCY_KEY = '/^[\x21-\x7E]{8,200}$/';

    public function __construct(private readonly TenantContext $tenant) {}

    public function index(Request $request, ActiveOrganization $active, ProjectDirectory $directory, CursorCodec $cursors): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'between:1,100'],
            'cursor' => ['sometimes', 'string', 'max:1024'],
            'status' => ['sometimes', 'string', 'in:'.implode(',', UpdateProject::STATUSES)],
            'type' => ['sometimes', 'string', 'in:'.implode(',', CreateProject::TYPES)],
            'confidentiality' => ['sometimes', 'string', 'in:'.implode(',', CreateProject::CONFIDENTIALITY)],
        ]);
        $filters = array_filter(
            ['status' => $validated['status'] ?? null, 'type' => $validated['type'] ?? null, 'confidentiality' => $validated['confidentiality'] ?? null],
            fn ($value) => $value !== null,
        );
        $limit = (int) ($validated['limit'] ?? 25);
        $resource = 'projects:'.$active->id->value.':'.http_build_query($filters);
        $after = isset($validated['cursor']) ? $this->position($cursors->decode($resource, (string) $validated['cursor'])) : null;
        $user = $this->user($request);

        $page = $this->tenant->runFor($user, $active->id, fn () => $directory->page($user, $filters, $after, $limit + 1));

        $next = null;
        if (count($page) > $limit) {
            $page = array_slice($page, 0, $limit);
            $last = $page[$limit - 1];
            $next = $cursors->encode($resource, [$last->key, $last->id]);
        }

        return new JsonResponse(['data' => array_map(self::resource(...), $page), 'next_cursor' => $next]);
    }

    public function show(Request $request, ActiveOrganization $active, ProjectDirectory $directory): JsonResponse
    {
        $user = $this->user($request);
        $projectId = (string) $request->route('project');

        $project = $this->tenant->runFor($user, $active->id, fn () => $directory->find($user, $projectId)) ?? throw ProjectNotFound::make();

        return new JsonResponse(['data' => self::resource($project)]);
    }

    public function store(Request $request, ActiveOrganization $active, CreateProject $create, ProjectStore $projects, ProjectVisibility $visibility, IdempotencyStore $idempotency): JsonResponse
    {
        $validated = $request->validate([
            'key' => ['required', 'string', 'regex:'.CreateProject::KEY_PATTERN],
            'name' => ['required', 'string', 'max:120', 'regex:/^[^\\x00]*$/u'],
            'type' => ['sometimes', 'string', 'in:'.implode(',', CreateProject::TYPES)],
            'confidentiality' => ['sometimes', 'string', 'in:'.implode(',', CreateProject::CONFIDENTIALITY)],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000', 'regex:/^[^\\x00]*$/u'],
        ]);
        $user = $this->user($request);

        $body = $this->tenant->runFor($user, $active->id, fn () => $this->visibleOrNotFound($user, $visibility, $this->idempotent($request, $idempotency, 'projects.store', $validated, function () use ($user, $create, $projects, $validated) {
            $projectId = $create->handle(
                $user,
                (string) $validated['key'],
                (string) $validated['name'],
                (string) ($validated['type'] ?? 'general'),
                (string) ($validated['confidentiality'] ?? 'standard'),
                isset($validated['description']) ? (string) $validated['description'] : null,
            );

            return ['data' => self::resource($projects->find($projectId) ?? throw ProjectNotFound::make())];
        })));

        return new JsonResponse($body, 201);
    }

    public function update(Request $request, ActiveOrganization $active, UpdateProject $update, ProjectStore $projects): JsonResponse
    {
        $validated = $request->validate([
            'expected_version' => ['required', 'integer', 'min:0'],
            'name' => ['sometimes', 'string', 'max:120', 'regex:/^[^\\x00]*$/u'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000', 'regex:/^[^\\x00]*$/u'],
            'type' => ['sometimes', 'string', 'in:'.implode(',', CreateProject::TYPES)],
            'status' => ['sometimes', 'string', 'in:'.implode(',', UpdateProject::STATUSES)],
            'confidentiality' => ['sometimes', 'string', 'in:'.implode(',', CreateProject::CONFIDENTIALITY)],
        ]);
        /** @var array{name?: string, description?: string|null, type?: string, status?: string, confidentiality?: string} $changes */
        $changes = array_diff_key($validated, ['expected_version' => true]);
        if ($changes === []) {
            abort(422, 'Nothing to change.');
        }
        $user = $this->user($request);
        $projectId = (string) $request->route('project');

        $project = $this->tenant->runFor($user, $active->id, function () use ($user, $update, $projects, $projectId, $validated, $changes) {
            $update->handle($user, $projectId, (int) $validated['expected_version'], $changes);

            return $projects->find($projectId) ?? throw ProjectNotFound::make();
        });

        return new JsonResponse(['data' => self::resource($project)]);
    }

    public function addMember(Request $request, ActiveOrganization $active, AddProjectMember $add): Response
    {
        $validated = $request->validate([
            'membership_id' => ['required', 'string', 'max:64'],
            'role' => ['sometimes', 'string', 'in:member,manager'],
        ]);
        $user = $this->user($request);
        $projectId = (string) $request->route('project');

        $this->tenant->runFor($user, $active->id, fn () => $add->handle($user, $projectId, (string) $validated['membership_id'], (string) ($validated['role'] ?? 'member')));

        return response()->noContent();
    }

    public function removeMember(Request $request, ActiveOrganization $active, RemoveProjectMember $remove): Response
    {
        $user = $this->user($request);
        $projectId = (string) $request->route('project');
        $membershipId = (string) $request->route('membership');

        $this->tenant->runFor($user, $active->id, fn () => $remove->handle($user, $projectId, $membershipId));

        return response()->noContent();
    }

    public function linkTeam(Request $request, ActiveOrganization $active, LinkProjectTeam $link): Response
    {
        $validated = $request->validate(['team_id' => ['required', 'string', 'max:64']]);
        $user = $this->user($request);
        $projectId = (string) $request->route('project');

        $this->tenant->runFor($user, $active->id, fn () => $link->handle($user, $projectId, (string) $validated['team_id']));

        return response()->noContent();
    }

    public function unlinkTeam(Request $request, ActiveOrganization $active, UnlinkProjectTeam $unlink): Response
    {
        $user = $this->user($request);
        $projectId = (string) $request->route('project');
        $teamId = (string) $request->route('team');

        $this->tenant->runFor($user, $active->id, fn () => $unlink->handle($user, $projectId, $teamId));

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private static function resource(ProjectSummary $project): array
    {
        return [
            'id' => $project->id,
            'key' => $project->key,
            'name' => $project->name,
            'description' => $project->description,
            'type' => $project->type,
            'status' => $project->status,
            'confidentiality' => $project->confidentiality,
            'version' => $project->version,
        ];
    }

    /**
     * A replayed response is only returned while the actor can still see the
     * project: the stored body is not a lasting authorization.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function visibleOrNotFound(Authenticatable $actor, ProjectVisibility $visibility, array $body): array
    {
        $data = $body['data'] ?? null;
        if (! is_array($data) || ! is_string($data['id'] ?? null) || ! $visibility->canView($actor, $data['id'])) {
            throw ProjectNotFound::make();
        }

        return $body;
    }

    /**
     * @param  list<string>  $position
     * @return array{0: string, 1: string}
     */
    private function position(array $position): array
    {
        if (count($position) !== 2 || ! ProjectStore::isUuid($position[1])) {
            throw InvalidCursor::make();
        }

        return [$position[0], $position[1]];
    }

    /**
     * Optional Idempotency-Key, scoped to the user and the operation, so
     * another member reusing a key never receives someone else's response.
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
