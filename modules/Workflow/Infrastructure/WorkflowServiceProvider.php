<?php

namespace Modules\Workflow\Infrastructure;

use Illuminate\Support\ServiceProvider;
use Modules\Platform\Contracts\Modules\ModuleManifest;
use Modules\Workflow\Contracts\WorkflowManifest;

final class WorkflowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([WorkflowManifest::class], ModuleManifest::TAG);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__).'/Database/Migrations');
    }
}
