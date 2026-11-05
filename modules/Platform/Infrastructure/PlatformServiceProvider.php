<?php

namespace Modules\Platform\Infrastructure;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Database\Console\DumpCommand;
use Illuminate\Database\Console\Seeds\SeedCommand;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Modules\Platform\Contracts\Events\DomainEventRecorder;
use Modules\Platform\Contracts\Http\CursorCodec;
use Modules\Platform\Contracts\Idempotency\IdempotencyStore;
use Modules\Platform\Contracts\Modules\ModuleManifest;
use Modules\Platform\Contracts\Observability\CorrelationId;
use Modules\Platform\Contracts\PlatformManifest;
use Modules\Platform\Contracts\Tenancy\AdmissionVerifier;
use Modules\Platform\Contracts\Tenancy\IntegrationVerifier;
use Modules\Platform\Contracts\Tenancy\MembershipVerifier;
use Modules\Platform\Contracts\Tenancy\TenantContext;
use Modules\Platform\Infrastructure\Database\MigrationConnectionGuard;
use Modules\Platform\Infrastructure\Events\PostgresDomainEventRecorder;
use Modules\Platform\Infrastructure\Http\HmacCursorCodec;
use Modules\Platform\Infrastructure\Idempotency\PostgresIdempotencyStore;
use Modules\Platform\Infrastructure\Tenancy\DenyAllAdmissionVerifier;
use Modules\Platform\Infrastructure\Tenancy\DenyAllIntegrationVerifier;
use Modules\Platform\Infrastructure\Tenancy\DenyAllMembershipVerifier;
use Modules\Platform\Infrastructure\Tenancy\PostgresTenantContext;
use Modules\Platform\Presentation\Console\RelayOutboxCommand;
use Modules\Platform\Presentation\Console\ResetTestDatabaseCommand;
use Modules\Platform\Presentation\Console\VerifyDatabaseRolesCommand;

final class PlatformServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Fail closed until 01-identity-tenancy binds the real verifier.
        $this->app->bind(MembershipVerifier::class, DenyAllMembershipVerifier::class);
        $this->app->bind(AdmissionVerifier::class, DenyAllAdmissionVerifier::class);
        $this->app->bind(IntegrationVerifier::class, DenyAllIntegrationVerifier::class);
        $this->app->scoped(TenantContext::class, PostgresTenantContext::class);
        $this->app->bind(DomainEventRecorder::class, PostgresDomainEventRecorder::class);
        $this->app->bind(IdempotencyStore::class, PostgresIdempotencyStore::class);
        $this->app->singleton(CursorCodec::class, function ($app): CursorCodec {
            $key = (string) $app['config']->get('app.key');
            if ($key === '') {
                throw new \RuntimeException('APP_KEY is required to sign pagination cursors.');
            }

            return new HmacCursorCodec($key);
        });
        $this->app->tag([PlatformManifest::class], ModuleManifest::TAG);
    }

    public function boot(): void
    {
        // Every tenant write route (POST/PATCH/DELETE) uses throttle:writes.
        RateLimiter::for('writes', fn (Request $request) => Limit::perMinute(60)->by('user:'.$request->user()?->getAuthIdentifier()));
        // Read-heavy, scope-computing routes (monitoring) use throttle:reads.
        RateLimiter::for('reads', fn (Request $request) => Limit::perMinute(300)->by('user:'.$request->user()?->getAuthIdentifier()));

        $prohibitDestructive = ! config('database.allow_destructive_commands');

        // Covers db:wipe, migrate:fresh, migrate:refresh, migrate:reset, migrate:rollback.
        DB::prohibitDestructiveCommands($prohibitDestructive);
        // Not covered by the framework: seeding writes data and schema:dump --prune deletes migrations.
        SeedCommand::prohibit($prohibitDestructive);
        DumpCommand::prohibit($prohibitDestructive);

        Event::listen(CommandStarting::class, [MigrationConnectionGuard::class, 'handle']);

        // The framework only re-routes Symfony console events to CommandStarting
        // when APP_ENV is not "testing" (runningUnitTests()). Without this,
        // `migrate --env=testing` would skip the guard entirely, including the
        // check against falling back to .env when .env.testing is missing.
        $this->app->booted(function (): void {
            $this->app->make(ConsoleKernel::class)->rerouteSymfonyCommandEvents();
        });

        // Jobs inherit the dispatcher's correlation id through Laravel Context;
        // jobs dispatched without one (scheduler, CLI) get a fresh id.
        Event::listen(JobProcessing::class, fn () => CorrelationId::current());

        $this->loadMigrationsFrom(dirname(__DIR__).'/Database/Migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([RelayOutboxCommand::class, ResetTestDatabaseCommand::class, VerifyDatabaseRolesCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('orbixa:outbox:relay')->everyMinute()->withoutOverlapping()->onOneServer();
        });

    }
}
