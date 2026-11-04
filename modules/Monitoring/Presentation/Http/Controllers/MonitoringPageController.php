<?php

namespace Modules\Monitoring\Presentation\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Access\Contracts\Permission;
use Modules\IdentityTenancy\Contracts\ActiveOrganization;
use Modules\Monitoring\Application\ChangeGrant;
use Modules\Monitoring\Application\GrantGuard;
use Modules\Monitoring\Application\GrantQueries;
use Modules\Monitoring\Application\MonitoringQueries;
use Modules\Platform\Contracts\Tenancy\TenantContext;

/** Minimal monitoring screens until 11-design-system (M10); same rules as the API. */
final class MonitoringPageController
{
    private const PAGE_SIZE = 50;

    public function __construct(private readonly TenantContext $tenant) {}

    public function panel(Request $request, ActiveOrganization $active, MonitoringQueries $queries, GrantGuard $guard): View
    {
        $user = $this->user($request);

        [$counts, $items, $canAdminister] = $this->tenant->runFor($user, $active->id, function () use ($user, $queries, $guard) {
            $scope = $queries->scope($user);

            return [
                $queries->overview($user, $scope),
                $queries->items($user, $scope, [], null, self::PAGE_SIZE),
                $guard->allows($user, Permission::MonitoringGrantsView),
            ];
        });

        return view('monitoring::monitoring.panel', [
            'organizationId' => $active->id->value,
            'counts' => $counts,
            'items' => $items,
            'canAdminister' => $canAdminister,
        ]);
    }

    public function item(Request $request, ActiveOrganization $active, MonitoringQueries $queries): View
    {
        $user = $this->user($request);
        $itemId = (string) $request->route('item');

        $item = $this->tenant->runFor($user, $active->id, fn () => $queries->item($user, $queries->scope($user), $itemId));

        return view('monitoring::monitoring.item', ['organizationId' => $active->id->value, 'item' => $item]);
    }

    public function grants(Request $request, ActiveOrganization $active, GrantQueries $queries, GrantGuard $guard): View
    {
        $user = $this->user($request);

        [$grants, $canManage] = $this->tenant->runFor($user, $active->id, fn () => [
            $queries->page($user, null, true, null, 100),
            $guard->allows($user, Permission::MonitoringGrantsManage),
        ]);

        return view('monitoring::monitoring.grants', [
            'organizationId' => $active->id->value,
            'grants' => $grants,
            'canManage' => $canManage,
        ]);
    }

    public function revoke(Request $request, ActiveOrganization $active, ChangeGrant $change): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:500', 'regex:/^[^\p{Cc}\p{Cf}]*$/u']]);
        $user = $this->user($request);
        $grantId = (string) $request->route('grant');

        $this->tenant->runFor($user, $active->id, fn () => $change->revoke($user, $grantId, isset($validated['reason']) ? (string) $validated['reason'] : null));

        return redirect()->route('monitoring.grants', ['organization' => $active->id->value])->with('status', 'Concesión revocada.');
    }

    private function user(Request $request): Authenticatable
    {
        return $request->user() ?? abort(401);
    }
}
