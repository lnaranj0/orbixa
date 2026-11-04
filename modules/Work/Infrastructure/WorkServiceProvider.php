<?php

namespace Modules\Work\Infrastructure;

use Illuminate\Support\ServiceProvider;
use Modules\Platform\Contracts\Modules\ModuleManifest;
use Modules\Work\Contracts\WorkManifest;

final class WorkServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([WorkManifest::class], ModuleManifest::TAG);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__).'/Database/Migrations');
    }
}
