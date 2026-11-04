<?php

namespace Modules\Portfolio\Infrastructure;

use Illuminate\Support\ServiceProvider;
use Modules\Access\Contracts\ScopedRoleProvider;
use Modules\IdentityTenancy\Contracts\OrganizationLifecycle;
use Modules\Platform\Contracts\Modules\ModuleManifest;
use Modules\Portfolio\Contracts\PortfolioManifest;
use Modules\Portfolio\Contracts\ProjectDirectory;
use Modules\Portfolio\Contracts\ProjectVisibility;

final class PortfolioServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([PortfolioManifest::class], ModuleManifest::TAG);
        $this->app->tag([PortfolioLifecycle::class], OrganizationLifecycle::TAG);
        $this->app->tag([PortfolioScopedRoleProvider::class], ScopedRoleProvider::TAG);
        $this->app->bind(ProjectVisibility::class, PostgresProjectVisibility::class);
        $this->app->bind(ProjectDirectory::class, PostgresProjectDirectory::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__).'/Database/Migrations');
    }
}
