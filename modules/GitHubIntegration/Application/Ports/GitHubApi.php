<?php

namespace Modules\GitHubIntegration\Application\Ports;

use Modules\GitHubIntegration\Domain\Secret;

/**
 * Read-only port to GitHub (G9, G10). The only non-GET calls behind it are
 * token issuance. Failures raise GitHubApiFailure, whose message never
 * carries a response body, a header or a credential.
 */
interface GitHubApi
{
    /** App JWT: GET /app/installations/{id}. Null when GitHub does not know it. */
    public function installation(int $installationId): ?GitHubInstallation;

    /** Installation token: GET /installation/repositories, one page. */
    public function installationRepositories(int $installationId, int $page = 1): RepositoryPage;

    /**
     * OAuth user-to-server: POST github.com/login/oauth/access_token. The user
     * token is used within the request and never stored.
     */
    public function exchangeOAuthCode(#[\SensitiveParameter] string $code): Secret;

    /**
     * User token: GET /user/installations. Ids of the App installations the
     * user can actually access (G-C).
     *
     * @return list<int>
     */
    public function userInstallationIds(Secret $userToken): array;

    /**
     * User token: whether the user administers the installation's account
     * (G-C, review H1). Access to one repository is not enough to link the
     * whole installation. Organization: GET /user/memberships/orgs/{login},
     * active admin. User account: GET /user is that account.
     */
    public function isAccountAdmin(Secret $userToken, GitHubInstallation $installation): bool;
}
