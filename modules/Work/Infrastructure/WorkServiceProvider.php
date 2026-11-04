<?php

namespace Modules\Work\Infrastructure;

use Illuminate\Support\ServiceProvider;
use Modules\IdentityTenancy\Contracts\OrganizationLifecycle;
use Modules\Platform\Contracts\Modules\ModuleManifest;
use Modules\Work\Contracts\Monitoring\MonitoredWorkReader;
use Modules\Work\Contracts\WorkManifest;

final class WorkServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([WorkManifest::class], ModuleManifest::TAG);
        $this->app->tag([WorkLifecycle::class], OrganizationLifecycle::TAG);
        $this->app->bind(MonitoredWorkReader::class, PostgresMonitoredWorkReader::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__).'/Database/Migrations');
        $this->loadRoutesFrom(dirname(__DIR__).'/Presentation/routes.php');
        $this->loadViewsFrom(dirname(__DIR__).'/Presentation/views', 'work');
    }
}
