<?php

namespace Modules\Teams\Infrastructure;

use Illuminate\Support\ServiceProvider;
use Modules\Platform\Contracts\Modules\ModuleManifest;
use Modules\Teams\Contracts\TeamsManifest;

final class TeamsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([TeamsManifest::class], ModuleManifest::TAG);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__).'/Database/Migrations');
    }
}
