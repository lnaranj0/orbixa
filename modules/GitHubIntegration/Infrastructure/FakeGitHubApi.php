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

    /** @var array<string, list<int>> OAuth code / user token => installations whose account the user administers */
    private array $admins = [];

    /** @var list<string> */
    private array $calls = [];

    /** @var (\Closure(string): void)|null runs before every call; may throw or change state */
    private ?\Closure $beforeCall = null;

    /** @param (\Closure(string): void)|null $hook */
    public function beforeCall(?\Closure $hook): void
    {
        $this->beforeCall = $hook;
    }

    /** Make GitHub answer a lookup of $requested with another installation. */
    public function respondInstallation(int $requested, GitHubInstallation $response): void
    {
        $this->installations[$requested] = $response;
    }

    /** @param list<GitHubRepository> $repositories */
    public function addInstallation(GitHubInstallation $installation, array $repositories = []): void
    {
        $this->installations[$installation->id] = $installation;
        $this->repositories[$installation->id] = $repositories;
    }

    /**
     * @param  list<int>  $installationIds  installations the user can access
     * @param  list<int>|null  $adminOf  of those, whose account the user administers (default: all)
     */
    public function grantUser(string $code, array $installationIds, ?array $adminOf = null): void
    {
        $this->grants[$code] = $installationIds;
        $this->admins[$code] = $adminOf ?? $installationIds;
    }

    /** @return list<string> */
    public function calls(): array
    {
        return $this->calls;
    }

    public function installation(int $installationId): ?GitHubInstallation
    {
        $this->record("installation:{$installationId}");

        return $this->installations[$installationId] ?? null;
    }

    public function installationRepositories(int $installationId, int $page = 1): RepositoryPage
    {
        $this->record("repositories:{$installationId}:{$page}");
        if (! isset($this->installations[$installationId]) || $this->installations[$installationId]->suspended) {
            throw GitHubApiFailure::status('installation_token', 404);
        }
        $chunks = array_chunk($this->repositories[$installationId], 100);

        return new RepositoryPage($chunks[$page - 1] ?? [], isset($chunks[$page]) ? $page + 1 : null);
    }

    public function exchangeOAuthCode(#[\SensitiveParameter] string $code): Secret
    {
        $this->record('oauth');
        // A code is single use, as on GitHub.
        $installations = $this->grants[$code] ?? throw GitHubApiFailure::unexpected('oauth');
        $token = 'fake-user-token-'.bin2hex(random_bytes(8));
        $this->users[$token] = $installations;
        $this->admins[$token] = $this->admins[$code] ?? [];
        unset($this->grants[$code], $this->admins[$code]);

        return new Secret($token);
    }

    public function userInstallationIds(Secret $userToken): array
    {
        $this->record('user_installations');

        return $this->users[$userToken->reveal()] ?? throw GitHubApiFailure::status('user_installations', 401);
    }

    public function isAccountAdmin(Secret $userToken, GitHubInstallation $installation): bool
    {
        $this->record('account_admin');
        if (! isset($this->users[$userToken->reveal()])) {
            throw GitHubApiFailure::status('membership', 401);
        }

        return in_array($installation->id, $this->admins[$userToken->reveal()] ?? [], true);
    }

    private function record(string $call): void
    {
        $this->calls[] = $call;
        if ($this->beforeCall !== null) {
            ($this->beforeCall)($call);
        }
    }
}
