<?php

namespace Modules\GitHubIntegration\Infrastructure;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
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
