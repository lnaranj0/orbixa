<?php

namespace Modules\Portfolio\Infrastructure;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\IdentityTenancy\Contracts\CurrentMembership;
use Modules\Platform\Contracts\Events\ActorReference;
use Modules\Platform\Contracts\Events\DomainEvent;
use Modules\Platform\Contracts\Events\DomainEventRecorder;
use Modules\Portfolio\Contracts\ProjectDirectory;
use Modules\Portfolio\Contracts\ProjectSummary;
use Modules\Portfolio\Contracts\ProjectVisibility;

final class PostgresProjectDirectory implements ProjectDirectory
{
    public function __construct(
        private readonly ProjectVisibility $visibility,
        private readonly ProjectStore $projects,
        private readonly CurrentMembership $memberships,
        private readonly DomainEventRecorder $events,
    ) {}

    public function find(Authenticatable $actor, string $projectId): ?ProjectSummary
    {
        if (! $this->visibility->canView($actor, $projectId)) {
            return null;
        }

        $project = $this->projects->find($projectId);

        // D3: opening a restricted project through the organization role
        // (not as a direct member) leaves an audit trace.
        $membershipId = $this->memberships->idFor($actor);
        if ($project !== null && $project->confidentiality === 'restricted' && $membershipId !== null
            && ! $this->projects->isActiveMember($project->id, $membershipId)) {
            $this->events->record(
                new DomainEvent('audit.sensitive_access.granted', 1, 'project', $project->id, [
                    'reason' => 'organization_role',
                    'viewer_membership_id' => $membershipId,
                ]),
                ActorReference::human((string) $actor->getAuthIdentifier()),
            );
        }

        return $project;
    }

    public function visible(Authenticatable $actor): array
    {
        $accessible = $this->visibility->accessibleProjects($actor);

        return $this->projects->list($accessible->all ? null : $accessible->ids);
    }
}
