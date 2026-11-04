<?php

namespace Modules\Workflow\Infrastructure;

use Illuminate\Support\ServiceProvider;
use Modules\Platform\Contracts\Modules\ModuleManifest;
use Modules\Work\Contracts\Workflow\TransitionPolicy;
use Modules\Workflow\Contracts\WorkflowManifest;

final class WorkflowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([WorkflowManifest::class], ModuleManifest::TAG);
        $this->app->bind(TransitionPolicy::class, PostgresTransitionPolicy::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__).'/Database/Migrations');
        $this->loadRoutesFrom(dirname(__DIR__).'/Presentation/routes.php');
        $this->loadViewsFrom(dirname(__DIR__).'/Presentation/views', 'workflow');
    }
}
