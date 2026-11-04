<?php

use App\Providers\AppServiceProvider;
use App\Providers\HorizonServiceProvider;
use Modules\Audit\Infrastructure\AuditServiceProvider;
use Modules\Platform\Infrastructure\PlatformServiceProvider;

// Module providers are registered explicitly (no auto-discovery) so the list
// of active modules stays reviewable.
return [
    AppServiceProvider::class,
    HorizonServiceProvider::class,
    PlatformServiceProvider::class,
    AuditServiceProvider::class,
];
