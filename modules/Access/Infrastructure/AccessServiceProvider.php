<?php

namespace Modules\Access\Infrastructure;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Access\Contracts\AccessManifest;
use Modules\Access\Contracts\Authorizer;
use Modules\Access\Contracts\Permission;
use Modules\IdentityTenancy\Contracts\OrganizationLifecycle;
use Modules\Platform\Contracts\Modules\ModuleManifest;

final class AccessServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([AccessManifest::class], ModuleManifest::TAG);
        $this->app->tag([AccessLifecycle::class], OrganizationLifecycle::TAG);
        $this->app->bind(Authorizer::class, PostgresAuthorizer::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__).'/Database/Migrations');

        // One Gate ability per permission, evaluated in the active tenant
        // context. Undefined abilities are denied by Laravel (deny-by-default).
        foreach (Permission::cases() as $permission) {
            Gate::define($permission->value, fn (?Authenticatable $user) => $user !== null
                && $this->app->make(Authorizer::class)->can($user, $permission));
        }
    }
}
