<?php

namespace Modules\Monitoring\Infrastructure;

use Illuminate\Support\ServiceProvider;
use Modules\IdentityTenancy\Contracts\OrganizationLifecycle;
use Modules\Monitoring\Contracts\MonitoringManifest;
use Modules\Platform\Contracts\Modules\ModuleManifest;

final class MonitoringServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([MonitoringManifest::class], ModuleManifest::TAG);
        $this->app->tag([MonitoringLifecycle::class], OrganizationLifecycle::TAG);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__).'/Database/Migrations');
    }
}
