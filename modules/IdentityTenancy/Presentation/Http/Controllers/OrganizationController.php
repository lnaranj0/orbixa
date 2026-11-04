<?php

namespace Modules\IdentityTenancy\Presentation\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;
use Modules\IdentityTenancy\Application\CreateOrganization;
use Modules\IdentityTenancy\Contracts\ActiveOrganization;
use Modules\IdentityTenancy\Contracts\MembershipDirectory;
use Modules\IdentityTenancy\Infrastructure\Membership\MemberList;
use Modules\Platform\Contracts\Tenancy\MembershipVerifier;
use Modules\Platform\Contracts\Tenancy\OrganizationId;
use Modules\Platform\Contracts\Tenancy\TenantContext;

final class OrganizationController
{
    public function index(Request $request, MembershipDirectory $directory): View
    {
        return view('identity::organizations.index', [
            'organizations' => $directory->organizationsFor($this->user($request)),
        ]);
    }

    public function store(Request $request, CreateOrganization $create): RedirectResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:120']]);

        $organization = $create->handle($this->user($request), (string) $validated['name']);

        return redirect()->route('organizations.show', ['organization' => $organization->value]);
    }

    /** POST switch: verify, remember, regenerate the session id (D9). */
    public function switch(Request $request, MembershipVerifier $memberships): RedirectResponse
    {
        try {
            $organization = OrganizationId::fromString((string) $request->input('organization'));
        } catch (InvalidArgumentException) {
            abort(404);
        }

        if (! $memberships->hasActiveMembership($this->user($request), $organization)) {
            abort(404);
        }

        $request->session()->put(ActiveOrganization::SESSION_KEY, $organization->value);
        $request->session()->regenerate();

        return redirect()->route('organizations.show', ['organization' => $organization->value]);
    }

    public function show(Request $request, ActiveOrganization $active, TenantContext $tenant, MemberList $members): View
    {
        $name = $tenant->runFor($this->user($request), $active->id, fn () => $members->organizationName());

        return view('identity::organizations.show', ['organizationId' => $active->id->value, 'name' => $name]);
    }

    private function user(Request $request): Authenticatable
    {
        return $request->user() ?? abort(401);
    }
}
