<?php

namespace Modules\GitHubIntegration\Infrastructure;

use Illuminate\Support\ServiceProvider;
use Modules\GitHubIntegration\Contracts\GitHubIntegrationManifest;
use Modules\Platform\Contracts\Modules\ModuleManifest;
use Modules\Platform\Contracts\Tenancy\IntegrationVerifier;

final class GitHubIntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([GitHubIntegrationManifest::class], ModuleManifest::TAG);
        // Replaces Platform's deny-all integration verifier (06 G-B).
        $this->app->bind(IntegrationVerifier::class, InstallationRouter::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__).'/Database/Migrations');
    }
}
