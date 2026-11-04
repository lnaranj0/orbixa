<?php

namespace Modules\Planning\Infrastructure;

use Illuminate\Support\ServiceProvider;
use Modules\Planning\Contracts\PlanningManifest;
use Modules\Platform\Contracts\Modules\ModuleManifest;

final class PlanningServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([PlanningManifest::class], ModuleManifest::TAG);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__).'/Database/Migrations');
        $this->loadRoutesFrom(dirname(__DIR__).'/Presentation/routes.php');
        $this->loadViewsFrom(dirname(__DIR__).'/Presentation/views', 'planning');
    }
}
