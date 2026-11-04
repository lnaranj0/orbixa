<?php

namespace Modules\Portfolio\Infrastructure;

use Illuminate\Support\ServiceProvider;
use Modules\Platform\Contracts\Modules\ModuleManifest;
use Modules\Portfolio\Contracts\PortfolioManifest;

final class PortfolioServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([PortfolioManifest::class], ModuleManifest::TAG);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__).'/Database/Migrations');
    }
}
