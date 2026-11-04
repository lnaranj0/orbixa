<?php

namespace Modules\Work\Infrastructure;

use Illuminate\Support\ServiceProvider;
use Modules\IdentityTenancy\Contracts\OrganizationLifecycle;
use Modules\Platform\Contracts\Modules\ModuleManifest;
use Modules\Work\Application\WorkGuard;
use Modules\Work\Contracts\FlowMetrics;
use Modules\Work\Contracts\Monitoring\MonitoredWorkReader;
use Modules\Work\Contracts\Planning\ItemCategories;
use Modules\Work\Contracts\ReadOnlyItemAccess;
use Modules\Work\Contracts\Workflow\StatusUsage;
use Modules\Work\Contracts\WorkManifest;

final class WorkServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([WorkManifest::class], ModuleManifest::TAG);
        $this->app->tag([WorkLifecycle::class], OrganizationLifecycle::TAG);
        $this->app->bind(MonitoredWorkReader::class, PostgresMonitoredWorkReader::class);
        $this->app->bind(StatusUsage::class, PostgresStatusUsage::class);
        $this->app->bind(ItemCategories::class, PostgresItemCategories::class);
        $this->app->bind(FlowMetrics::class, PostgresFlowMetrics::class);
        $this->app->when(WorkGuard::class)->needs('$readOnlyAccess')->giveTagged(ReadOnlyItemAccess::TAG);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__).'/Database/Migrations');
        $this->loadRoutesFrom(dirname(__DIR__).'/Presentation/routes.php');
        $this->loadViewsFrom(dirname(__DIR__).'/Presentation/views', 'work');
    }
}
