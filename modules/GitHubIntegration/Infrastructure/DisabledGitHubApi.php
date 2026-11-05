<?php

namespace Modules\GitHubIntegration\Infrastructure;

use Modules\GitHubIntegration\Application\Ports\GitHubApi;
use Modules\GitHubIntegration\Application\Ports\GitHubApiFailure;
use Modules\GitHubIntegration\Application\Ports\GitHubInstallation;
use Modules\GitHubIntegration\Application\Ports\RepositoryPage;
use Modules\GitHubIntegration\Domain\Secret;

/** Default driver: fails closed until a GitHub App is configured. */
final class DisabledGitHubApi implements GitHubApi
{
    public function installation(int $installationId): ?GitHubInstallation
    {
        throw GitHubApiFailure::notConfigured('driver');
    }

    public function installationRepositories(int $installationId, int $page = 1): RepositoryPage
    {
        throw GitHubApiFailure::notConfigured('driver');
    }

    public function exchangeOAuthCode(#[\SensitiveParameter] string $code): Secret
    {
        throw GitHubApiFailure::notConfigured('driver');
    }

    public function userInstallationIds(Secret $userToken): array
    {
        throw GitHubApiFailure::notConfigured('driver');
    }
}
