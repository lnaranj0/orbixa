<?php

namespace Modules\Portfolio\Infrastructure;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\IdentityTenancy\Contracts\CurrentMembership;
use Modules\Portfolio\Contracts\ProjectDirectory;
use Modules\Portfolio\Contracts\ProjectReference;
use Modules\Portfolio\Contracts\ProjectSummary;
use Modules\Portfolio\Contracts\ProjectVisibility;

final class PostgresProjectDirectory implements ProjectDirectory
{
    public function __construct(
        private readonly ProjectVisibility $visibility,
        private readonly ProjectStore $projects,
        private readonly CurrentMembership $memberships,
        private readonly SensitiveAccessAudit $audit,
    ) {}

    public function find(Authenticatable $actor, string $projectId): ?ProjectSummary
    {
        if (! $this->visibility->canView($actor, $projectId)) {
            return null;
        }

        $project = $this->projects->find($projectId);
        if ($project !== null) {
            $this->recordSensitiveAccess($actor, $project->id, 'view');
        }

        return $project;
    }

    public function reference(Authenticatable $actor, string $projectId): ?ProjectReference
    {
        if (! $this->visibility->canView($actor, $projectId)) {
            return null;
        }
        $project = $this->projects->find($projectId);

        return $project === null ? null : new ProjectReference($project->id, $project->key, $project->status, $project->confidentiality);
    }

    public function recordSensitiveAccess(Authenticatable $actor, string $projectId, string $operation): void
    {
        $membershipId = $this->memberships->idFor($actor);
        $project = $this->projects->find($projectId);

        if ($project !== null && $project->confidentiality === 'restricted' && $membershipId !== null
            && ! $this->projects->isActiveMember($project->id, $membershipId)) {
            $this->audit->record($actor, $membershipId, $project->id, $operation);
        }
    }

    public function visible(Authenticatable $actor): array
    {
        $accessible = $this->visibility->accessibleProjects($actor);

        return $this->redacted($actor, $accessible->all, $this->projects->list($accessible->all ? null : $accessible->ids));
    }

    public function page(Authenticatable $actor, array $filters, ?array $after, int $limit): array
    {
        $accessible = $this->visibility->accessibleProjects($actor);

        return $this->redacted($actor, $accessible->all, $this->projects->page($accessible->all ? null : $accessible->ids, $filters, $after, $limit));
    }

    /**
     * Only organization-level viewers ($all) can see restricted projects
     * they are not direct members of; for those, drop the description.
     *
     * @param  list<ProjectSummary>  $projects
     * @return list<ProjectSummary>
     */
    private function redacted(Authenticatable $actor, bool $all, array $projects): array
    {
        $membershipId = $this->memberships->idFor($actor);
        if (! $all || $membershipId === null) {
            return $projects;
        }

        $direct = array_flip($this->projects->directProjectIdsOf($membershipId));

        return array_map(
            fn (ProjectSummary $p) => $p->confidentiality === 'restricted' && ! isset($direct[$p->id]) ? $p->withoutDescription() : $p,
            $projects,
        );
    }
}
