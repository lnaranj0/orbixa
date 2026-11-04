<?php

namespace Modules\IdentityTenancy\Infrastructure;

use Illuminate\Bus\Dispatcher as BusDispatcher;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Modules\IdentityTenancy\Application\AcceptInvitation;
use Modules\IdentityTenancy\Application\CreateOrganization;
use Modules\IdentityTenancy\Application\RevokeMembership;
use Modules\IdentityTenancy\Contracts\ActiveOrganization;
use Modules\IdentityTenancy\Contracts\CurrentMembership;
use Modules\IdentityTenancy\Contracts\IdentityTenancyManifest;
use Modules\IdentityTenancy\Contracts\MembershipDirectory;
use Modules\IdentityTenancy\Contracts\MembershipLookup;
use Modules\IdentityTenancy\Contracts\OrganizationLifecycle;
use Modules\IdentityTenancy\Contracts\RoleGrantGuard;
use Modules\IdentityTenancy\Infrastructure\Invitations\DenyAllRoleGrantGuard;
use Modules\IdentityTenancy\Infrastructure\Invitations\PostgresAdmissionVerifier;
use Modules\IdentityTenancy\Infrastructure\Jobs\RunTenantAwareJobs;
use Modules\IdentityTenancy\Infrastructure\Membership\PostgresCurrentMembership;
use Modules\IdentityTenancy\Infrastructure\Membership\PostgresMembershipDirectory;
use Modules\IdentityTenancy\Infrastructure\Membership\PostgresMembershipLookup;
use Modules\IdentityTenancy\Infrastructure\Membership\PostgresMembershipVerifier;
use Modules\IdentityTenancy\Presentation\Console\CreateUserCommand;
use Modules\IdentityTenancy\Presentation\Http\Middleware\ResolveActiveOrganization;
use Modules\Platform\Contracts\Modules\ModuleManifest;
use Modules\Platform\Contracts\Tenancy\AdmissionVerifier;
use Modules\Platform\Contracts\Tenancy\MembershipVerifier;

final class IdentityTenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([IdentityTenancyManifest::class], ModuleManifest::TAG);

        // Replaces Platform's deny-all default (this provider registers after Platform).
        $this->app->bind(MembershipVerifier::class, PostgresMembershipVerifier::class);
        $this->app->bind(MembershipDirectory::class, PostgresMembershipDirectory::class);
        $this->app->bind(CurrentMembership::class, PostgresCurrentMembership::class);
        $this->app->bind(MembershipLookup::class, PostgresMembershipLookup::class);
        $this->app->when([CreateOrganization::class, AcceptInvitation::class, RevokeMembership::class])
            ->needs('$lifecycle')->giveTagged(OrganizationLifecycle::TAG);

        // Replaces Platform's deny-all admission; Access replaces the deny-all grant guard.
        $this->app->bind(AdmissionVerifier::class, PostgresAdmissionVerifier::class);
        $this->app->bind(RoleGrantGuard::class, DenyAllRoleGrantGuard::class);
    }

    public function boot(): void
    {
        // Tenant-aware queued jobs re-verify membership on every execution.
        $this->app->make(BusDispatcher::class)->pipeThrough([RunTenantAwareJobs::class]);

        $this->loadMigrationsFrom(dirname(__DIR__).'/Database/Migrations');
        $this->app->make(Router::class)->aliasMiddleware(ActiveOrganization::MIDDLEWARE, ResolveActiveOrganization::class);
        $this->loadRoutesFrom(dirname(__DIR__).'/Presentation/routes.php');
        $this->loadViewsFrom(dirname(__DIR__).'/Presentation/views', 'identity');

        if ($this->app->runningInConsole()) {
            $this->commands([CreateUserCommand::class]);
        }
    }
}
