<?php

namespace Modules\GitHubIntegration\Infrastructure;

use Modules\GitHubIntegration\Application\Ports\GitHubApi;
use Modules\GitHubIntegration\Application\Ports\GitHubApiFailure;
use Modules\GitHubIntegration\Application\Ports\GitHubInstallation;
use Modules\GitHubIntegration\Application\Ports\GitHubRepository;
use Modules\GitHubIntegration\Application\Ports\RepositoryPage;
use Modules\GitHubIntegration\Domain\Secret;

/**
 * In-memory GitHub for development and tests (G10: no real App in
 * development). The provider refuses it in production.
 */
final class FakeGitHubApi implements GitHubApi
{
    /** @var array<int, GitHubInstallation> */
    private array $installations = [];

    /** @var array<int, list<GitHubRepository>> */
    private array $repositories = [];

    /** @var array<string, list<int>> OAuth code => installation ids the user can access */
    private array $grants = [];

    /** @var array<string, list<int>> user token => installation ids */
    private array $users = [];

    /** @var list<string> */
    private array $calls = [];

    /** @param list<GitHubRepository> $repositories */
    public function addInstallation(GitHubInstallation $installation, array $repositories = []): void
    {
        $this->installations[$installation->id] = $installation;
        $this->repositories[$installation->id] = $repositories;
    }

    /** @param list<int> $installationIds */
    public function grantUser(string $code, array $installationIds): void
    {
        $this->grants[$code] = $installationIds;
    }

    /** @return list<string> */
    public function calls(): array
    {
        return $this->calls;
    }

    public function installation(int $installationId): ?GitHubInstallation
    {
        $this->calls[] = "installation:{$installationId}";

        return $this->installations[$installationId] ?? null;
    }

    public function installationRepositories(int $installationId, int $page = 1): RepositoryPage
    {
        $this->calls[] = "repositories:{$installationId}:{$page}";
        if (! isset($this->installations[$installationId]) || $this->installations[$installationId]->suspended) {
            throw GitHubApiFailure::status('installation_token', 404);
        }
        $chunks = array_chunk($this->repositories[$installationId], 100);

        return new RepositoryPage($chunks[$page - 1] ?? [], isset($chunks[$page]) ? $page + 1 : null);
    }

    public function exchangeOAuthCode(#[\SensitiveParameter] string $code): Secret
    {
        $this->calls[] = 'oauth';
        // A code is single use, as on GitHub.
        $installations = $this->grants[$code] ?? throw GitHubApiFailure::unexpected('oauth');
        unset($this->grants[$code]);
        $token = 'fake-user-token-'.bin2hex(random_bytes(8));
        $this->users[$token] = $installations;

        return new Secret($token);
    }

    public function userInstallationIds(Secret $userToken): array
    {
        $this->calls[] = 'user_installations';

        return $this->users[$userToken->reveal()] ?? throw GitHubApiFailure::status('user_installations', 401);
    }
}
