<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\HorizonServiceProvider;
use Modules\Access\Infrastructure\AccessServiceProvider;
use Modules\Audit\Infrastructure\AuditServiceProvider;
use Modules\IdentityTenancy\Infrastructure\IdentityTenancyServiceProvider;
use Modules\Platform\Infrastructure\PlatformServiceProvider;
use Modules\Portfolio\Infrastructure\PortfolioServiceProvider;
use Modules\Teams\Infrastructure\TeamsServiceProvider;
use Modules\Work\Infrastructure\WorkServiceProvider;

// Module providers are registered explicitly (no auto-discovery) so the list
// of active modules stays reviewable.
return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    HorizonServiceProvider::class,
    PlatformServiceProvider::class,
    AuditServiceProvider::class,
    IdentityTenancyServiceProvider::class,
    AccessServiceProvider::class,
    TeamsServiceProvider::class,
    PortfolioServiceProvider::class,
    WorkServiceProvider::class,
];
