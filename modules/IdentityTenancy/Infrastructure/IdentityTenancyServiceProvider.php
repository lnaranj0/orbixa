<?php

namespace Modules\IdentityTenancy\Infrastructure;

use Illuminate\Support\ServiceProvider;
use Modules\IdentityTenancy\Contracts\IdentityTenancyManifest;
use Modules\IdentityTenancy\Contracts\MembershipDirectory;
use Modules\IdentityTenancy\Infrastructure\Membership\PostgresMembershipDirectory;
use Modules\IdentityTenancy\Infrastructure\Membership\PostgresMembershipVerifier;
use Modules\Platform\Contracts\Modules\ModuleManifest;
use Modules\Platform\Contracts\Tenancy\MembershipVerifier;

final class IdentityTenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([IdentityTenancyManifest::class], ModuleManifest::TAG);

        // Replaces Platform's deny-all default (this provider registers after Platform).
        $this->app->bind(MembershipVerifier::class, PostgresMembershipVerifier::class);
        $this->app->bind(MembershipDirectory::class, PostgresMembershipDirectory::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__).'/Database/Migrations');
    }
}
