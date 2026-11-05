<?php

namespace Modules\GitHubIntegration\Presentation\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\GitHubIntegration\Application\ConnectInstallation;
use Modules\GitHubIntegration\Application\InstallationRejected;
use Modules\GitHubIntegration\Contracts\GitHubOperationDenied;
use Modules\GitHubIntegration\Infrastructure\Setup\SetupState;
use Modules\IdentityTenancy\Contracts\ActiveOrganization;
use Modules\Platform\Contracts\Tenancy\TenantAccessDenied;

/**
 * App installation (G-C). `start` issues the session-bound state and the
 * GitHub URL; `callback` is the App's fixed setup URL: the organization comes
 * from the consumed state, never from the request.
 *
 * The callback carries the OAuth code in its query: it never sends a
 * Referer, and a guest is sent to log in without remembering the URL (the
 * "intended" URL would keep the code in the session). The web server should
 * also keep this path's query out of its access log.
 */
final class GitHubSetupController
{
    public function __construct(
        private readonly SetupState $states,
        private readonly Config $config,
    ) {}

    public function start(Request $request, ActiveOrganization $active, ConnectInstallation $connect): JsonResponse
    {
        $user = $this->user($request);
        $connect->authorize($user, $active->id);
        $slug = (string) $this->config->get('github.app_slug');
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,99}$/', $slug) !== 1) {
            return new JsonResponse(['message' => 'The GitHub integration is not configured.'], 503);
        }
        $state = $this->states->issue($request->session(), $user, $active->id);

        return new JsonResponse(['data' => [
            'redirect_url' => "https://github.com/apps/{$slug}/installations/new?".http_build_query(['state' => $state]),
        ]]);
    }

    public function callback(Request $request, ConnectInstallation $connect): RedirectResponse
    {
        $user = $request->user();
        $organization = $this->states->consume($request->session(), $user, $request->query('state'));
        if ($user === null) {
            return $this->private(redirect()->route('login'));
        }
        if ($organization === null) {
            return $this->rejected(null);
        }

        try {
            // An organization member asked the owner to approve the installation on GitHub.
            if ($request->query('setup_action') === 'request') {
                $connect->authorize($user, $organization);

                return $this->private(redirect()->route('organizations.show', ['organization' => $organization->value])
                    ->with('status', 'The GitHub installation is waiting for approval on GitHub.'));
            }
            $installationId = filter_var($request->query('installation_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $code = $request->query('code');
            if ($installationId === false || ! is_string($code) || preg_match('/^[A-Za-z0-9_-]{1,100}$/', $code) !== 1) {
                return $this->rejected($organization->value);
            }
            $connect->complete($user, $organization, $installationId, $code);
        } catch (InstallationRejected|GitHubOperationDenied|TenantAccessDenied) {
            return $this->rejected($organization->value);
        }

        return $this->private(redirect()->route('organizations.show', ['organization' => $organization->value])
            ->with('status', 'GitHub installation linked. Select the repositories to index.'));
    }

    private function rejected(?string $organization): RedirectResponse
    {
        $to = $organization === null ? redirect()->route('organizations.index') : redirect()->route('organizations.show', ['organization' => $organization]);

        return $this->private($to->withErrors(['github' => InstallationRejected::MESSAGE]));
    }

    private function private(RedirectResponse $response): RedirectResponse
    {
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    private function user(Request $request): Authenticatable
    {
        return $request->user() ?? abort(401);
    }
}
