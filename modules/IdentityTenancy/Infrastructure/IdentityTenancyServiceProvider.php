<?php

namespace Modules\IdentityTenancy\Infrastructure;

use Illuminate\Support\ServiceProvider;
use Modules\IdentityTenancy\Contracts\IdentityTenancyManifest;
use Modules\Platform\Contracts\Modules\ModuleManifest;

final class IdentityTenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([IdentityTenancyManifest::class], ModuleManifest::TAG);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__).'/Database/Migrations');
    }
}
