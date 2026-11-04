<?php

namespace Modules\Access\Infrastructure;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Access\Contracts\AccessManifest;
use Modules\Access\Contracts\Authorizer;
use Modules\Access\Contracts\Permission;
use Modules\Access\Contracts\Scope;
use Modules\Access\Contracts\ScopedRoleProvider;
use Modules\IdentityTenancy\Contracts\OrganizationLifecycle;
use Modules\IdentityTenancy\Contracts\RoleGrantGuard;
use Modules\Platform\Contracts\Modules\ModuleManifest;

final class AccessServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([AccessManifest::class], ModuleManifest::TAG);
        $this->app->tag([AccessLifecycle::class], OrganizationLifecycle::TAG);
        $this->app->bind(Authorizer::class, PostgresAuthorizer::class);
        $this->app->bind(RoleGrantGuard::class, AccessRoleGrantGuard::class);
        $this->app->when(PostgresAuthorizer::class)->needs('$scopedRoles')->giveTagged(ScopedRoleProvider::TAG);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__).'/Database/Migrations');

        // One Gate ability per permission, evaluated in the active tenant
        // context. Undefined abilities are denied by Laravel (deny-by-default).
        foreach (Permission::cases() as $permission) {
            // Optional Scope argument: Gate::forUser($u)->allows('teams.manage', [Scope::team($id)]).
            Gate::define($permission->value, function (?Authenticatable $user, ?Scope $scope = null) use ($permission): bool {
                if ($user === null) {
                    return false;
                }

                $authorizer = $this->app->make(Authorizer::class);

                return $scope === null ? $authorizer->can($user, $permission) : $authorizer->canWithin($user, $permission, $scope);
            });
        }
    }
}
