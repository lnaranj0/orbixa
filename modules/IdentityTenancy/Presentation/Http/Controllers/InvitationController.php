<?php

namespace Modules\IdentityTenancy\Presentation\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;
use Modules\IdentityTenancy\Application\AcceptInvitation;
use Modules\IdentityTenancy\Contracts\ActiveOrganization;
use Modules\IdentityTenancy\Contracts\InvitationRejected;
use Modules\Platform\Contracts\Tenancy\OrganizationId;

/**
 * The emailed link carries the token in the query string. It is moved into
 * the (encrypted) session and the user is redirected to a clean URL, so the
 * token does not stay in history, referrers or later logs.
 */
final class InvitationController
{
    private const PENDING = 'orbixa.pending_invitation';

    public function open(Request $request, string $organization): RedirectResponse|View
    {
        $organizationId = $this->organization($organization);
        $token = $request->query('token');

        if (is_string($token) && $token !== '') {
            $request->session()->put(self::PENDING, ['organization' => $organizationId->value, 'token' => $token]);

            return redirect()->route('invitations.open', ['organization' => $organizationId->value]);
        }

        $pending = $request->session()->get(self::PENDING);
        if (! is_array($pending) || ($pending['organization'] ?? null) !== $organizationId->value) {
            abort(404);
        }

        return view('identity::invitations.accept', ['organizationId' => $organizationId->value]);
    }

    public function accept(Request $request, AcceptInvitation $accept, string $organization): RedirectResponse
    {
        $organizationId = $this->organization($organization);
        $pending = $request->session()->pull(self::PENDING);

        if (! is_array($pending) || ($pending['organization'] ?? null) !== $organizationId->value || ! is_string($pending['token'] ?? null)) {
            abort(404);
        }

        try {
            $accept->handle($this->user($request), $organizationId, $pending['token']);
        } catch (InvitationRejected $rejected) {
            return redirect()->route('organizations.index')->withErrors(['invitation' => $rejected->getMessage()]);
        }

        $request->session()->put(ActiveOrganization::SESSION_KEY, $organizationId->value);
        $request->session()->regenerate();

        return redirect()->route('organizations.show', ['organization' => $organizationId->value]);
    }

    private function organization(string $value): OrganizationId
    {
        try {
            return OrganizationId::fromString($value);
        } catch (InvalidArgumentException) {
            abort(404);
        }
    }

    private function user(Request $request): Authenticatable
    {
        return $request->user() ?? abort(401);
    }
}
