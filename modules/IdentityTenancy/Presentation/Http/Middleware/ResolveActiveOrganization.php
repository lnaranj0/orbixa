<?php

namespace Modules\IdentityTenancy\Presentation\Http\Middleware;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Modules\IdentityTenancy\Contracts\ActiveOrganization;
use Modules\Platform\Contracts\Tenancy\MembershipVerifier;
use Modules\Platform\Contracts\Tenancy\OrganizationId;
use Symfony\Component\HttpFoundation\Response;

/**
 * /o/{organization}: malformed id, unknown organization, another
 * organization and revoked membership all produce the same 404. Switching
 * the remembered organization regenerates the session id (D9).
 */
final class ResolveActiveOrganization
{
    public function __construct(
        private readonly MembershipVerifier $memberships,
        private readonly Container $container,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user() ?? abort(404);

        try {
            $organization = OrganizationId::fromString((string) $request->route('organization'));
        } catch (InvalidArgumentException) {
            abort(404);
        }

        $session = $request->session();

        if (! $this->memberships->hasActiveMembership($user, $organization)) {
            if ($session->get(ActiveOrganization::SESSION_KEY) === $organization->value) {
                $session->forget(ActiveOrganization::SESSION_KEY);
            }

            abort(404);
        }

        if ($session->get(ActiveOrganization::SESSION_KEY) !== $organization->value) {
            $session->put(ActiveOrganization::SESSION_KEY, $organization->value);
            $session->regenerate();
        }

        $this->container->instance(ActiveOrganization::class, new ActiveOrganization($organization));

        return $next($request);
    }
}
