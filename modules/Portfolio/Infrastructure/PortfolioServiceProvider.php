<?php

namespace Modules\Portfolio\Infrastructure;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        $this->loadRoutesFrom(dirname(__DIR__).'/Presentation/routes.php');
        $this->loadViewsFrom(dirname(__DIR__).'/Presentation/views', 'portfolio');

        // D11: project creation is throttled per user (key enumeration).
        RateLimiter::for('projects.create', fn (Request $request) => Limit::perMinute(10)->by('user:'.$request->user()?->getAuthIdentifier()));
    }
}
