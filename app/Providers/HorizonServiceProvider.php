<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Register the Horizon gate.
     *
     * Horizon shows job payloads of every organization (cross-tenant), so the
     * dashboard is denied to everyone outside the local environment. Access
     * for the platform_operator role is defined in 01-identity-tenancy, with
     * its own audited grant.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', fn ($user = null): bool => false);
    }
}
