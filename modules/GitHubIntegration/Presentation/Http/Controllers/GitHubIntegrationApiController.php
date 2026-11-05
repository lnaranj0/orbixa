<?php

namespace Modules\GitHubIntegration\Presentation\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\GitHubIntegration\Application\ManageRepositories;
use Modules\IdentityTenancy\Contracts\ActiveOrganization;
use Modules\Platform\Contracts\Tenancy\TenantContext;

/** GitHub integration API (G12): overview and repository selection, with integrations.github.manage. */
final class GitHubIntegrationApiController
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function show(Request $request, ActiveOrganization $active, ManageRepositories $repositories): JsonResponse
    {
        $user = $this->user($request);

        return new JsonResponse(['data' => $this->tenant->runFor($user, $active->id, fn () => $repositories->overview($user))]);
    }

    public function select(Request $request, ActiveOrganization $active, ManageRepositories $repositories): JsonResponse
    {
        $user = $this->user($request);
        $id = (string) $request->route('repository');
        $this->tenant->runFor($user, $active->id, fn () => $repositories->select($user, $id));

        return new JsonResponse(null, 204);
    }

    public function deselect(Request $request, ActiveOrganization $active, ManageRepositories $repositories): JsonResponse
    {
        $user = $this->user($request);
        $id = (string) $request->route('repository');
        $this->tenant->runFor($user, $active->id, fn () => $repositories->deselect($user, $id));

        return new JsonResponse(null, 204);
    }

    private function user(Request $request): Authenticatable
    {
        return $request->user() ?? abort(401);
    }
}
