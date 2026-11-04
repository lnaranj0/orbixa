<?php

namespace Modules\Reporting\Infrastructure;

use Illuminate\Support\ServiceProvider;
use Modules\Platform\Contracts\Modules\ModuleManifest;
use Modules\Reporting\Contracts\ReportingManifest;

final class ReportingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([ReportingManifest::class], ModuleManifest::TAG);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(dirname(__DIR__).'/Presentation/routes.php');
    }
}
