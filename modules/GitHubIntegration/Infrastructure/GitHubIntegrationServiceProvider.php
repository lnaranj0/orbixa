<?php

namespace Modules\GitHubIntegration\Infrastructure;

use Illuminate\Support\ServiceProvider;
use Modules\GitHubIntegration\Contracts\GitHubIntegrationManifest;
use Modules\Platform\Contracts\Modules\ModuleManifest;

final class GitHubIntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([GitHubIntegrationManifest::class], ModuleManifest::TAG);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__).'/Database/Migrations');
    }
}
