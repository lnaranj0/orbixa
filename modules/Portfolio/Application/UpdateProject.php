<?php

namespace Modules\Portfolio\Application;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Platform\Contracts\Events\ActorReference;
use Modules\Platform\Contracts\Events\DomainEvent;
use Modules\Platform\Contracts\Events\DomainEventRecorder;
use Modules\Platform\Contracts\Validation\InputRejected;
use Modules\Portfolio\Contracts\ProjectNotFound;
use Modules\Portfolio\Contracts\ProjectOperationDenied;
use Modules\Portfolio\Contracts\ProjectVersionConflict;
use Modules\Portfolio\Infrastructure\ProjectStore;
use Modules\Portfolio\Infrastructure\SensitiveAccessAudit;

/**
 * Optimistic update of the mutable project attributes (the key and the
 * creator are immutable, also at the grant level). A stale expected version
 * changes nothing and fails with ProjectVersionConflict (409).
 */
final class UpdateProject
{
    public const STATUSES = ['active', 'on_hold', 'archived'];

    public function __construct(
        private readonly ProjectGuard $guard,
        private readonly ProjectStore $projects,
        private readonly DomainEventRecorder $events,
        private readonly SensitiveAccessAudit $audit,
    ) {}

    /**
     * @param  array{name?: string, description?: string|null, type?: string, status?: string, confidentiality?: string}  $changes
     */
    public function handle(Authenticatable $actor, string $projectId, int $expectedVersion, array $changes): int
    {
        $actorMembershipId = $this->guard->context($actor);
        $this->guard->requireManage($actor, $projectId);

        $columns = $this->validated($changes);
        $current = $this->projects->find($projectId) ?? throw ProjectNotFound::make();

        // Lowering confidentiality widens access (linked teams start seeing
        // the project): organization-level only, like appointing a manager (D6).
        if ($current->confidentiality === 'restricted' && ($columns['confidentiality'] ?? 'restricted') === 'standard'
            && ! $this->guard->managesOrganizationProjects($actor)) {
            throw ProjectOperationDenied::because('Lowering confidentiality requires the organization-level projects.manage.');
        }
        if ($current->confidentiality === 'restricted' && ! $this->projects->isActiveMember($projectId, $actorMembershipId)) {
            $this->audit->record($actor, $actorMembershipId, $projectId, 'update');
        }

        if ($current->version !== $expectedVersion || ! $this->projects->update($projectId, $expectedVersion, $columns)) {
            throw ProjectVersionConflict::make();
        }

        $payload = ['fields' => array_keys($columns)];
        if (isset($columns['confidentiality']) && $columns['confidentiality'] !== $current->confidentiality) {
            $payload['confidentiality'] = ['from' => $current->confidentiality, 'to' => $columns['confidentiality']];
        }

        $this->events->record(
            new DomainEvent('project.updated', 1, 'project', $projectId, $payload),
            ActorReference::human((string) $actor->getAuthIdentifier()),
        );

        return $expectedVersion + 1;
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, string|null>
     */
    private function validated(array $changes): array
    {
        $unknown = array_diff(array_keys($changes), ['name', 'description', 'type', 'status', 'confidentiality']);
        if ($unknown !== [] || $changes === []) {
            throw InputRejected::because('Only name, description, type, status and confidentiality can be changed.');
        }

        $columns = [];
        foreach ($changes as $field => $value) {
            if ($field === 'description') {
                if ($value !== null && ! is_string($value)) {
                    throw InputRejected::because('Invalid project description.');
                }
                $columns[$field] = ProjectInput::description($value);

                continue;
            }
            if (! is_string($value)) {
                throw InputRejected::because("Invalid project {$field}.");
            }
            $columns[$field] = match ($field) {
                'name' => ProjectInput::name($value),
                'type' => self::checked($field, $value, CreateProject::TYPES),
                'status' => self::checked($field, $value, self::STATUSES),
                default => self::checked($field, $value, CreateProject::CONFIDENTIALITY),
            };
        }

        return $columns;
    }

    /**
     * @param  list<string>  $allowed
     */
    private static function checked(string $field, string $value, array $allowed): string
    {
        ProjectInput::oneOf($field, $value, $allowed);

        return $value;
    }
}
