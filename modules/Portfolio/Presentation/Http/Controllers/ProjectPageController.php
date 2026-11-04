<?php

namespace Modules\Portfolio\Presentation\Http\Controllers;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;
use Modules\IdentityTenancy\Contracts\ActiveOrganization;
use Modules\Platform\Contracts\Http\CursorCodec;
use Modules\Platform\Contracts\Http\InvalidCursor;
use Modules\Platform\Contracts\Tenancy\TenantContext;
use Modules\Portfolio\Application\CreateProject;
use Modules\Portfolio\Contracts\ProjectDirectory;
use Modules\Portfolio\Contracts\ProjectKeyUnavailable;
use Modules\Portfolio\Contracts\ProjectNotFound;
use Modules\Portfolio\Infrastructure\ProjectStore;

/** Minimal project screens until 11-design-system (D18); same rules as the API. */
final class ProjectPageController
{
    private const PAGE_SIZE = 25;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly Gate $gate,
    ) {}

    public function index(Request $request, ActiveOrganization $active, ProjectDirectory $directory, CursorCodec $cursors): View
    {
        $resource = 'projects-page:'.$active->id->value;
        $cursor = $request->query('cursor');
        $after = null;
        if (is_string($cursor) && $cursor !== '') {
            $position = $cursors->decode($resource, $cursor);
            if (count($position) !== 2 || ! ProjectStore::isUuid($position[1])) {
                throw InvalidCursor::make();
            }
            $after = [$position[0], $position[1]];
        }
        $user = $this->user($request);

        [$page, $canCreate] = $this->tenant->runFor($user, $active->id, fn () => [
            $directory->page($user, [], $after, self::PAGE_SIZE + 1),
            $this->gate->forUser($user)->allows('projects.create'),
        ]);

        $next = null;
        if (count($page) > self::PAGE_SIZE) {
            $page = array_slice($page, 0, self::PAGE_SIZE);
            $last = $page[self::PAGE_SIZE - 1];
            $next = $cursors->encode($resource, [$last->key, $last->id]);
        }

        return view('portfolio::projects.index', [
            'organizationId' => $active->id->value,
            'projects' => $page,
            'nextCursor' => $next,
            'canCreate' => $canCreate,
        ]);
    }

    public function show(Request $request, ActiveOrganization $active, ProjectDirectory $directory): View
    {
        $user = $this->user($request);
        $projectId = (string) $request->route('project');

        $project = $this->tenant->runFor($user, $active->id, fn () => $directory->find($user, $projectId)) ?? throw ProjectNotFound::make();

        return view('portfolio::projects.show', ['organizationId' => $active->id->value, 'project' => $project]);
    }

    public function store(Request $request, ActiveOrganization $active, CreateProject $create): RedirectResponse
    {
        $validated = $request->validate([
            'key' => ['required', 'string', 'regex:'.CreateProject::KEY_PATTERN],
            'name' => ['required', 'string', 'max:120', 'regex:/^[^\\x00]*$/u'],
            'type' => ['required', 'string', 'in:'.implode(',', CreateProject::TYPES)],
            'confidentiality' => ['required', 'string', 'in:'.implode(',', CreateProject::CONFIDENTIALITY)],
        ]);
        $user = $this->user($request);

        try {
            $projectId = $this->tenant->runFor($user, $active->id, fn () => $create->handle(
                $user, (string) $validated['key'], (string) $validated['name'], (string) $validated['type'], (string) $validated['confidentiality'],
            ));
        } catch (ProjectKeyUnavailable|InvalidArgumentException $exception) {
            return back()->withInput()->withErrors(['key' => $exception->getMessage()]);
        }

        return redirect()->route('projects.show', ['organization' => $active->id->value, 'project' => $projectId])->with('status', 'Proyecto creado.');
    }

    private function user(Request $request): Authenticatable
    {
        return $request->user() ?? abort(401);
    }
}
