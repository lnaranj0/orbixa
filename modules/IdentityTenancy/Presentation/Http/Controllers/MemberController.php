<?php

namespace Modules\IdentityTenancy\Presentation\Http\Controllers;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\IdentityTenancy\Application\InviteMember;
use Modules\IdentityTenancy\Application\RevokeMembership;
use Modules\IdentityTenancy\Contracts\ActiveOrganization;
use Modules\IdentityTenancy\Contracts\MembershipChangeDenied;
use Modules\IdentityTenancy\Infrastructure\Membership\MemberList;
use Modules\Platform\Contracts\Tenancy\TenantContext;

final class MemberController
{
    /** Roles offered in the invite form; the grant guard still decides. */
    private const INVITABLE_ROLES = ['org_admin', 'project_manager', 'team_lead', 'developer', 'reviewer', 'viewer'];

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly Gate $gate,
    ) {}

    public function index(Request $request, ActiveOrganization $active, MemberList $members): View
    {
        $user = $this->user($request);

        return $this->tenant->runFor($user, $active->id, function () use ($user, $active, $members) {
            $canInvite = $this->gate->forUser($user)->allows('members.invite');
            $canManage = $this->gate->forUser($user)->allows('members.manage');

            if (! $canInvite && ! $canManage) {
                throw MembershipChangeDenied::because('members.invite or members.manage is required.');
            }

            return view('identity::members.index', [
                'organizationId' => $active->id->value,
                'organizationName' => $members->organizationName(),
                'members' => $members->active(),
                'canInvite' => $canInvite,
                'canManage' => $canManage,
                'roles' => self::INVITABLE_ROLES,
            ]);
        });
    }

    public function invite(Request $request, ActiveOrganization $active, InviteMember $invite): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:254'],
            'role' => ['required', 'string', 'in:'.implode(',', self::INVITABLE_ROLES)],
        ]);
        $user = $this->user($request);

        $issued = $this->tenant->runFor($user, $active->id, fn () => $invite->handle($user, (string) $validated['email'], (string) $validated['role']));

        // Shown once (flash, encrypted session). Not emailed yet: the dev
        // mailer is "log", which would write the token to the logs.
        return redirect()
            ->route('organizations.members', ['organization' => $active->id->value])
            ->with('invitation_link', route('invitations.open', ['organization' => $active->id->value, 'token' => $issued->ticket->reveal()]));
    }

    public function revoke(Request $request, ActiveOrganization $active, RevokeMembership $revoke, string $organization, string $membership): RedirectResponse
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $membership) !== 1) {
            abort(404);
        }

        $user = $this->user($request);
        $this->tenant->runFor($user, $active->id, fn () => $revoke->handle($user, $membership));

        return redirect()->route('organizations.members', ['organization' => $active->id->value])->with('status', 'Membresía revocada.');
    }

    private function user(Request $request): Authenticatable
    {
        return $request->user() ?? abort(401);
    }
}
