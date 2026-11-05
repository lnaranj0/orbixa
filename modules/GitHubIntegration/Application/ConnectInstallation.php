<?php

namespace Modules\GitHubIntegration\Application;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Modules\GitHubIntegration\Application\Ports\GitHubApi;
use Modules\GitHubIntegration\Application\Ports\GitHubApiFailure;
use Modules\GitHubIntegration\Application\Ports\GitHubInstallation;
use Modules\GitHubIntegration\Application\Ports\GitHubRepository;
use Modules\Platform\Contracts\Events\ActorReference;
use Modules\Platform\Contracts\Events\DomainEvent;
use Modules\Platform\Contracts\Events\DomainEventRecorder;
use Modules\Platform\Contracts\Tenancy\OrganizationId;
use Modules\Platform\Contracts\Tenancy\TenantContext;

/**
 * Links a GitHub App installation to an organization (G-C). The
 * installation_id of the setup callback is not trusted: GitHub must confirm,
 * with the user's own OAuth token, that the user can access it, and with the
 * App JWT that it exists and is not suspended. GitHub is called outside any
 * database transaction; the write re-checks the permission in its own.
 *
 * A second organization cannot claim a linked installation. Re-installing in
 * the same organization only refreshes it (and its repository list).
 * Repositories arrive "available"; none is selected here.
 */
final class ConnectInstallation
{
    /** Up to 1000 repositories here; the rest arrive by events or reconciliation. */
    private const MAX_REPOSITORY_PAGES = 10;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly DatabaseManager $db,
        private readonly GitHubApi $github,
        private readonly ManagePermission $permission,
        private readonly DomainEventRecorder $events,
    ) {}

    /** Fails before anything is sent to GitHub when the actor may not manage the integration. */
    public function authorize(Authenticatable $actor, OrganizationId $organization): void
    {
        $this->tenant->runFor($actor, $organization, fn () => $this->permission->require($actor));
    }

    /**
     * @return string the installation row id
     *
     * @throws InstallationRejected for every GitHub-side reason
     */
    public function complete(Authenticatable $actor, OrganizationId $organization, int $installationId, #[\SensitiveParameter] string $code): string
    {
        $this->authorize($actor, $organization);

        try {
            $userToken = $this->github->exchangeOAuthCode($code);
            if (! in_array($installationId, $this->github->userInstallationIds($userToken), true)) {
                throw InstallationRejected::make();
            }
            $installation = $this->github->installation($installationId);
            if ($installation === null || $installation->id !== $installationId || $installation->suspended
                || ! in_array($installation->accountType, ['User', 'Organization'], true) || trim($installation->accountLogin) === ''
                // Access to some repository is not enough: only an admin of the account links it all (review H1).
                || ! $this->github->isAccountAdmin($userToken, $installation)) {
                throw InstallationRejected::make();
            }
            [$repositories, $complete] = $this->repositories($installationId);
        } catch (GitHubApiFailure $failure) {
            report($failure);

            throw InstallationRejected::make();
        }

        try {
            return $this->tenant->runFor($actor, $organization, fn () => $this->store($actor, $installation, $repositories, $complete));
        } catch (QueryException $exception) {
            // Not chained: the framework message embeds the bindings.
            report(new \RuntimeException('Linking a GitHub installation failed (SQLSTATE '.($exception->errorInfo[0] ?? 'unknown').').'));

            throw InstallationRejected::make();
        }
    }

    /** @return array{list<GitHubRepository>, bool} the repositories and whether the list is complete */
    private function repositories(int $installationId): array
    {
        $repositories = [];
        $page = 1;
        for ($fetched = 0; $page !== null && $fetched < self::MAX_REPOSITORY_PAGES; $fetched++) {
            $result = $this->github->installationRepositories($installationId, $page);
            array_push($repositories, ...$result->repositories);
            $page = $result->nextPage;
        }

        return [$repositories, $page === null];
    }

    /** @param list<GitHubRepository> $repositories */
    private function store(Authenticatable $actor, GitHubInstallation $installation, array $repositories, bool $complete): string
    {
        $membershipId = $this->permission->require($actor);
        $login = mb_substr($installation->accountLogin, 0, 100);

        $existing = $this->db->selectOne(
            'select id::text as id, status from integrations.github_installations where installation_external_id = ? for update',
            [$installation->id]
        );
        if ($existing !== null) {
            $id = (string) $existing->id;
            $this->db->update(
                "update integrations.github_installations
                    set account_login = ?, status = 'active',
                        status_changed_at = case when status <> 'active' then now() else status_changed_at end,
                        updated_at = now(), lock_version = lock_version + 1
                  where id = ?",
                [$login, $id]
            );
            $connected = $existing->status !== 'active';
        } else {
            // A row of another organization is invisible here but still
            // conflicts on the global unique key: nothing is inserted.
            $inserted = $this->db->selectOne(
                'insert into integrations.github_installations (organization_id, installation_external_id, account_login, account_type, linked_by_membership_id)
                 values (platform.current_organization_id(), ?, ?, ?, ?)
                 on conflict (installation_external_id) do nothing
                 returning id::text as id',
                [$installation->id, $login, $installation->accountType, $membershipId]
            );
            if ($inserted === null) {
                throw InstallationRejected::make();
            }
            $id = (string) $inserted->id;
            $connected = true;
        }

        $this->refreshRepositories($id, $repositories, $complete);
        if ($connected) {
            $this->events->record(
                new DomainEvent('github.installation.connected', 1, 'github_installation', $id, ['repositories_available' => count($repositories)]),
                ActorReference::human((string) $actor->getAuthIdentifier()),
            );
        }

        return $id;
    }

    /** @param list<GitHubRepository> $repositories */
    private function refreshRepositories(string $installationId, array $repositories, bool $complete): void
    {
        foreach ($repositories as $repository) {
            if ($repository->id <= 0 || strlen($repository->fullName) < 3 || strlen($repository->fullName) > 200) {
                continue;
            }
            $this->db->insert(
                'insert into integrations.github_repositories (organization_id, installation_id, repository_external_id, full_name, is_private)
                 values (platform.current_organization_id(), ?, ?, ?, ?)
                 on conflict (organization_id, installation_id, repository_external_id)
                 do update set full_name = excluded.full_name, is_private = excluded.is_private, available = true, updated_at = now()',
                [$installationId, $repository->id, $repository->fullName, $repository->private]
            );
        }
        if ($complete) {
            // Removed from the installation on GitHub: kept as history, no longer indexed.
            $ids = array_map(fn (GitHubRepository $repository) => $repository->id, $repositories);
            $this->db->update(
                'update integrations.github_repositories set available = false, updated_at = now()
                  where installation_id = ? and available and not (repository_external_id = any (?::bigint[]))',
                [$installationId, '{'.implode(',', $ids).'}']
            );
        }
    }
}
