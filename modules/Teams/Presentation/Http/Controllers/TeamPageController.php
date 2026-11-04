<?php

namespace Modules\Teams\Presentation\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;
use Modules\IdentityTenancy\Contracts\ActiveOrganization;
use Modules\Platform\Contracts\Http\CursorCodec;
use Modules\Platform\Contracts\Http\InvalidCursor;
use Modules\Platform\Contracts\Tenancy\TenantContext;
use Modules\Teams\Application\CreateTeam;
use Modules\Teams\Application\TeamGuard;
use Modules\Teams\Application\TeamVisibility;
use Modules\Teams\Contracts\HierarchyViolation;
use Modules\Teams\Infrastructure\TeamStore;

/** Minimal team screens until 11-design-system (D18); same rules as the API. */
final class TeamPageController
{
    private const PAGE_SIZE = 25;

    public function __construct(private readonly TenantContext $tenant) {}

    public function index(Request $request, ActiveOrganization $active, TeamVisibility $visibility, TeamGuard $guard, TeamStore $teams, CursorCodec $cursors): View
    {
        $resource = 'teams-page:'.$active->id->value;
        $cursor = $request->query('cursor');
        $after = null;
        if (is_string($cursor) && $cursor !== '') {
            $after = $cursors->decode($resource, $cursor);
            if (count($after) !== 2 || ! TeamStore::isUuid($after[1])) {
                throw InvalidCursor::make();
            }
        }
        $user = $this->user($request);

        [$page, $canCreate] = $this->tenant->runFor($user, $active->id, fn () => [
            $teams->page($visibility->visibleIds($user), [], $after, self::PAGE_SIZE + 1),
            $guard->managesOrganizationTeams($user),
        ]);

        $next = null;
        if (count($page) > self::PAGE_SIZE) {
            $page = array_slice($page, 0, self::PAGE_SIZE);
            $last = $page[self::PAGE_SIZE - 1];
            $next = $cursors->encode($resource, [$last['name'], $last['id']]);
        }
        $names = array_column($page, 'name', 'id');

        return view('teams::teams.index', [
            'organizationId' => $active->id->value,
            'teams' => $page,
            'names' => $names,
            'nextCursor' => $next,
            'canCreate' => $canCreate,
        ]);
    }

    public function store(Request $request, ActiveOrganization $active, CreateTeam $create): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'parent_team_id' => ['nullable', 'string', 'max:64'],
        ]);
        $user = $this->user($request);
        $parent = isset($validated['parent_team_id']) && $validated['parent_team_id'] !== '' ? (string) $validated['parent_team_id'] : null;

        try {
            $this->tenant->runFor($user, $active->id, fn () => $create->handle($user, (string) $validated['name'], $parent));
        } catch (HierarchyViolation|InvalidArgumentException $exception) {
            return back()->withInput()->withErrors(['name' => $exception->getMessage()]);
        }

        return redirect()->route('teams.index', ['organization' => $active->id->value])->with('status', 'Equipo creado.');
    }

    private function user(Request $request): Authenticatable
    {
        return $request->user() ?? abort(401);
    }
}
