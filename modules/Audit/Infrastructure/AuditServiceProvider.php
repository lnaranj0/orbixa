<?php

namespace Modules\Audit\Infrastructure;

use Illuminate\Support\ServiceProvider;
use Modules\Audit\Contracts\AuditManifest;
use Modules\Platform\Contracts\Modules\ModuleManifest;

/**
 * Audit module skeleton (00-platform-foundation). Owns no tables yet:
 * the append-only audit log arrives with its first consumer.
 */
final class AuditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([AuditManifest::class], ModuleManifest::TAG);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__).'/Database/Migrations');
    }
}
