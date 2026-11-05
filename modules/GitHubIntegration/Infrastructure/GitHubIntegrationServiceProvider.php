<?php

namespace Modules\GitHubIntegration\Infrastructure;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Modules\GitHubIntegration\Application\Ports\GitHubApi;
use Modules\GitHubIntegration\Application\Ports\GitHubApiFailure;
use Modules\GitHubIntegration\Contracts\GitHubIntegrationManifest;
use Modules\GitHubIntegration\Infrastructure\Http\AppJwt;
use Modules\GitHubIntegration\Infrastructure\Http\HttpGitHubApi;
use Modules\GitHubIntegration\Infrastructure\Http\InstallationTokenCache;
use Modules\GitHubIntegration\Infrastructure\Secrets\GitHubSecrets;
use Modules\Platform\Contracts\Modules\ModuleManifest;
use Modules\Platform\Contracts\Tenancy\IntegrationVerifier;

final class GitHubIntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([GitHubIntegrationManifest::class], ModuleManifest::TAG);
        // Replaces Platform's deny-all integration verifier (06 G-B).
        $this->app->bind(IntegrationVerifier::class, InstallationRouter::class);

        $this->app->singleton(GitHubSecrets::class, fn (Application $app) => new GitHubSecrets((array) $app->make(Config::class)->get('github.secrets', [])));
        $this->app->singleton(GitHubApi::class, fn (Application $app): GitHubApi => match ($app->make(Config::class)->get('github.driver')) {
            'http' => $this->httpApi($app, $app->make(Config::class)),
            // The fake accepts any installation it is told about: never in production.
            'fake' => $app->environment('production') ? throw GitHubApiFailure::notConfigured('driver') : new FakeGitHubApi,
            default => new DisabledGitHubApi,
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__).'/Database/Migrations');
        $this->loadRoutesFrom(dirname(__DIR__).'/Presentation/routes.php');

        // The webhook reads raw bytes only; spare unauthenticated bodies the
        // recursive pass of TrimStrings / ConvertEmptyStringsToNull. (The
        // framework still decodes JSON once when capturing the request: the
        // body size must also be capped by the web server.)
        $isWebhook = fn (Request $request): bool => $request->is('webhooks/github');
        TrimStrings::skipWhen($isWebhook);
        ConvertEmptyStringsToNull::skipWhen($isWebhook);

        // Each completed setup costs ~20 GitHub calls, some on the App's quota
        // shared by every organization: a tighter budget than throttle:writes.
        RateLimiter::for('github-setup', function (Request $request): array {
            $by = $request->user() !== null ? 'user:'.$request->user()->getAuthIdentifier() : 'ip:'.$request->ip();

            // Per user only: a per-organization budget could be spent by any
            // member, even one who may not manage the integration.
            return [
                Limit::perMinute((int) config('github.setup_per_minute', 5))->by('github-setup:'.$by),
                Limit::perHour((int) config('github.setup_per_hour', 20))->by('github-setup-hour:'.$by),
            ];
        });
    }

    private function httpApi(Application $app, Config $config): HttpGitHubApi
    {
        $clientId = (string) $config->get('github.client_id');

        return new HttpGitHubApi(
            $app->make(HttpFactory::class),
            new AppJwt($app->make(GitHubSecrets::class), $clientId !== '' ? $clientId : (string) $config->get('github.app_id')),
            new InstallationTokenCache($app->make(CacheFactory::class)->store((string) $config->get('github.token_store')), $app->make(Encrypter::class)),
            $app->make(GitHubSecrets::class),
            $clientId,
            (int) $config->get('github.timeout', 10),
        );
    }
}
