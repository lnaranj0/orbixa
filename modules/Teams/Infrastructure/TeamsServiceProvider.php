<?php

namespace Modules\Teams\Infrastructure;

use Illuminate\Support\ServiceProvider;
use Modules\Access\Contracts\ScopedRoleProvider;
use Modules\IdentityTenancy\Contracts\OrganizationLifecycle;
use Modules\Platform\Contracts\Modules\ModuleManifest;
use Modules\Teams\Contracts\TeamDirectory;
use Modules\Teams\Contracts\TeamsManifest;

final class TeamsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([TeamsManifest::class], ModuleManifest::TAG);
        $this->app->tag([TeamsLifecycle::class], OrganizationLifecycle::TAG);
        $this->app->bind(TeamDirectory::class, PostgresTeamDirectory::class);
        $this->app->tag([TeamsScopedRoleProvider::class], ScopedRoleProvider::TAG);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__).'/Database/Migrations');
        $this->loadRoutesFrom(dirname(__DIR__).'/Presentation/routes.php');
        $this->loadViewsFrom(dirname(__DIR__).'/Presentation/views', 'teams');
    }
}
