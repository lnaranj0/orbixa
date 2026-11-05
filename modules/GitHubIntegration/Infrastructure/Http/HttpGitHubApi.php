<?php

namespace Modules\GitHubIntegration\Infrastructure\Http;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Modules\GitHubIntegration\Application\Ports\GitHubApi;
use Modules\GitHubIntegration\Application\Ports\GitHubApiFailure;
use Modules\GitHubIntegration\Application\Ports\GitHubInstallation;
use Modules\GitHubIntegration\Application\Ports\GitHubRepository;
use Modules\GitHubIntegration\Application\Ports\RepositoryPage;
use Modules\GitHubIntegration\Domain\Secret;
use Modules\GitHubIntegration\Infrastructure\Secrets\GitHubSecrets;

/**
 * The real GitHub App client (G9, G10). Every request goes through send(),
 * which allows GET on the API host and POST only to the two token-issuance
 * endpoints: Orbixa never writes to GitHub in 06. Redirects are not followed
 * and paging links must stay on the same host and path.
 */
final class HttpGitHubApi implements GitHubApi
{
    private const API = 'https://api.github.com';

    private const OAUTH_TOKEN_URL = 'https://github.com/login/oauth/access_token';

    private const MAX_USER_INSTALLATION_PAGES = 10;

    public function __construct(
        private readonly Factory $http,
        private readonly AppJwt $jwt,
        private readonly InstallationTokenCache $tokens,
        private readonly GitHubSecrets $secrets,
        private readonly string $clientId,
        private readonly int $timeout,
    ) {}

    public function installation(int $installationId): ?GitHubInstallation
    {
        $response = $this->send('GET', self::API."/app/installations/{$installationId}", 'installation', $this->jwt->issue());
        if ($response->status() === 404) {
            return null;
        }
        $body = $this->json($response, 'installation');
        $account = $body['account'] ?? null;
        if (! is_int($body['id'] ?? null) || ! is_array($account) || ! is_int($account['id'] ?? null)
            || ! is_string($account['login'] ?? null) || ! is_string($account['type'] ?? null)) {
            throw GitHubApiFailure::unexpected('installation');
        }

        return new GitHubInstallation($body['id'], $account['id'], $account['login'], $account['type'], ($body['suspended_at'] ?? null) !== null);
    }

    public function installationRepositories(int $installationId, int $page = 1): RepositoryPage
    {
        $path = '/installation/repositories';
        $response = $this->send('GET', self::API.$path, 'repositories', $this->installationToken($installationId), ['per_page' => 100, 'page' => max(1, $page)]);
        $body = $this->json($response, 'repositories');
        if (! is_array($body['repositories'] ?? null)) {
            throw GitHubApiFailure::unexpected('repositories');
        }
        $repositories = [];
        foreach ($body['repositories'] as $repository) {
            if (! is_array($repository) || ! is_int($repository['id'] ?? null) || ! is_string($repository['full_name'] ?? null) || ! is_bool($repository['private'] ?? null)) {
                throw GitHubApiFailure::unexpected('repositories');
            }
            $repositories[] = new GitHubRepository($repository['id'], $repository['full_name'], $repository['private']);
        }

        return new RepositoryPage($repositories, $this->nextPage($response, $path, 'repositories'));
    }

    public function exchangeOAuthCode(#[\SensitiveParameter] string $code): Secret
    {
        if ($this->clientId === '' || $code === '') {
            throw GitHubApiFailure::notConfigured('client_id');
        }
        $response = $this->send('POST', self::OAUTH_TOKEN_URL, 'oauth', null, [
            'client_id' => $this->clientId,
            'client_secret' => $this->secrets->oauthClientSecret()->reveal(),
            'code' => $code,
        ]);
        $token = $this->json($response, 'oauth')['access_token'] ?? null;
        if (! is_string($token) || $token === '') {
            // GitHub answers 200 with an "error" field for a bad or reused code.
            throw GitHubApiFailure::unexpected('oauth');
        }

        return new Secret($token);
    }

    public function userInstallationIds(Secret $userToken): array
    {
        $path = '/user/installations';
        $ids = [];
        $page = 1;
        for ($fetched = 0; $page !== null && $fetched < self::MAX_USER_INSTALLATION_PAGES; $fetched++) {
            $response = $this->send('GET', self::API.$path, 'user_installations', $userToken, ['per_page' => 100, 'page' => $page]);
            $installations = $this->json($response, 'user_installations')['installations'] ?? null;
            if (! is_array($installations)) {
                throw GitHubApiFailure::unexpected('user_installations');
            }
            foreach ($installations as $installation) {
                if (is_array($installation) && is_int($installation['id'] ?? null)) {
                    $ids[] = $installation['id'];
                }
            }
            $page = $this->nextPage($response, $path, 'user_installations');
        }

        return $ids;
    }

    public function isAccountAdmin(Secret $userToken, GitHubInstallation $installation): bool
    {
        if ($installation->accountType === 'User') {
            $id = $this->json($this->send('GET', self::API.'/user', 'user', $userToken), 'user')['id'] ?? null;

            return is_int($id) && $id === $installation->accountId;
        }
        if ($installation->accountType !== 'Organization' || preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,38})$/', $installation->accountLogin) !== 1) {
            return false;
        }
        $response = $this->send('GET', self::API.'/user/memberships/orgs/'.$installation->accountLogin, 'membership', $userToken);
        if (in_array($response->status(), [403, 404], true)) {
            return false;
        }
        $membership = $this->json($response, 'membership');
        $organization = $membership['organization'] ?? null;

        return ($membership['state'] ?? null) === 'active' && ($membership['role'] ?? null) === 'admin'
            && is_array($organization) && ($organization['id'] ?? null) === $installation->accountId;
    }

    private function installationToken(int $installationId): Secret
    {
        $cached = $this->tokens->get($installationId);
        if ($cached !== null) {
            return $cached;
        }
        $response = $this->send('POST', self::API."/app/installations/{$installationId}/access_tokens", 'installation_token', $this->jwt->issue());
        $body = $this->json($response, 'installation_token');
        $expiresAt = is_string($body['expires_at'] ?? null) ? strtotime($body['expires_at']) : false;
        if (! is_string($body['token'] ?? null) || $body['token'] === '' || $expiresAt === false) {
            throw GitHubApiFailure::unexpected('installation_token');
        }
        $token = new Secret($body['token']);
        $this->tokens->put($installationId, $token, $expiresAt);

        return $token;
    }

    /** @param array<string, int|string> $data */
    private function send(string $method, string $url, string $operation, ?Secret $bearer, array $data = []): Response
    {
        if ($method !== 'GET' && ! self::isTokenIssuance($method, $url)) {
            throw GitHubApiFailure::forbiddenWrite();
        }
        $request = $this->http
            ->timeout($this->timeout)
            ->withoutRedirecting()
            ->accept($url === self::OAUTH_TOKEN_URL ? 'application/json' : 'application/vnd.github+json')
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28', 'User-Agent' => 'Orbixa']);
        if ($bearer !== null) {
            $request = $request->withToken($bearer->reveal());
        }
        try {
            return $method === 'GET' ? $request->get($url, $data) : $request->post($url, $data);
        } catch (ConnectionException) {
            // Not chained: the transport exception may describe the request.
            throw GitHubApiFailure::unreachable($operation);
        }
    }

    private static function isTokenIssuance(string $method, string $url): bool
    {
        return $method === 'POST' && ($url === self::OAUTH_TOKEN_URL
            || preg_match('#^'.preg_quote(self::API, '#').'/app/installations/\d+/access_tokens$#', $url) === 1);
    }

    /** @return array<mixed> */
    private function json(Response $response, string $operation): array
    {
        if (! $response->successful()) {
            throw GitHubApiFailure::status($operation, $response->status());
        }
        $body = json_decode($response->body(), true);
        if (! is_array($body)) {
            throw GitHubApiFailure::unexpected($operation);
        }

        return $body;
    }

    /** The next page from the Link header, only if it points back to the same endpoint. */
    private function nextPage(Response $response, string $path, string $operation): ?int
    {
        if (preg_match('/<([^>]+)>;\s*rel="next"/', $response->header('Link'), $match) !== 1) {
            return null;
        }
        $next = parse_url($match[1]);
        parse_str($next['query'] ?? '', $query);
        $page = filter_var($query['page'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 2]]);
        if (($next['scheme'] ?? null) !== 'https' || ($next['host'] ?? null) !== 'api.github.com' || isset($next['port'])
            || isset($next['user']) || ($next['path'] ?? null) !== $path || $page === false) {
            throw GitHubApiFailure::unexpected($operation);
        }

        return $page;
    }
}
