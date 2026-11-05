<?php

namespace Modules\GitHubIntegration\Application;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\DatabaseManager;
use Modules\GitHubIntegration\Contracts\GitHubRepositoryNotFound;
use Modules\Platform\Contracts\Events\ActorReference;
use Modules\Platform\Contracts\Events\DomainEvent;
use Modules\Platform\Contracts\Events\DomainEventRecorder;
use Modules\Platform\Contracts\Validation\InputRejected;

/**
 * Integration overview and explicit repository selection (G-C): only
 * selected repositories of an active installation are indexed. Runs inside
 * the actor's tenant context; every call requires integrations.github.manage.
 * Deselecting keeps history: later events of the repository are ignored.
 */
final class ManageRepositories
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly ManagePermission $permission,
        private readonly DomainEventRecorder $events,
    ) {}

    /** @return array{installations: array<int, array<mixed>>, repositories: array<int, array<mixed>>} */
    public function overview(Authenticatable $actor): array
    {
        $this->permission->require($actor);

        return [
            'installations' => array_map(fn (object $row) => (array) $row, $this->db->select(
                'select id::text as id, installation_external_id, account_login, account_type, status, linked_at
                   from integrations.github_installations order by linked_at, id'
            )),
            'repositories' => array_map(fn (object $row) => (array) $row, $this->db->select(
                'select id::text as id, installation_id::text as installation_id, repository_external_id, full_name, is_private,
                        available, selected_at is not null as selected, selected_at
                   from integrations.github_repositories order by full_name, id'
            )),
        ];
    }

    public function select(Authenticatable $actor, string $repositoryId): void
    {
        $membershipId = $this->permission->require($actor);
        $repository = $this->lock($repositoryId);
        if ($repository->selected) {
            return;
        }
        if (! $repository->available || $repository->installation_status !== 'active') {
            throw InputRejected::because('The repository is not available for indexing.');
        }
        $this->db->update(
            'update integrations.github_repositories set selected_at = now(), selected_by_membership_id = ?, updated_at = now() where id = ?',
            [$membershipId, $repositoryId]
        );
        $this->event($actor, 'github.repository.selected', $repositoryId, $repository->installation_id);
    }

    public function deselect(Authenticatable $actor, string $repositoryId): void
    {
        $this->permission->require($actor);
        $repository = $this->lock($repositoryId);
        if (! $repository->selected) {
            return;
        }
        $this->db->update(
            'update integrations.github_repositories set selected_at = null, selected_by_membership_id = null, updated_at = now() where id = ?',
            [$repositoryId]
        );
        $this->event($actor, 'github.repository.deselected', $repositoryId, $repository->installation_id);
    }

    private function lock(string $repositoryId): \stdClass
    {
        $repository = preg_match(self::UUID, $repositoryId) === 1 ? $this->db->selectOne(
            'select r.installation_id::text as installation_id, r.available, r.selected_at is not null as selected, i.status as installation_status
               from integrations.github_repositories r
               join integrations.github_installations i on i.organization_id = r.organization_id and i.id = r.installation_id
              where r.id = ?
              for no key update of r',
            [$repositoryId]
        ) : null;

        return $repository instanceof \stdClass ? $repository : throw GitHubRepositoryNotFound::make();
    }

    private function event(Authenticatable $actor, string $name, string $repositoryId, string $installationId): void
    {
        $this->events->record(
            new DomainEvent($name, 1, 'github_repository', $repositoryId, ['installation_id' => $installationId]),
            ActorReference::human((string) $actor->getAuthIdentifier()),
        );
    }
}
