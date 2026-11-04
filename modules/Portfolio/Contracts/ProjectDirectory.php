<?php

namespace Modules\Portfolio\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/** Read access to projects, always filtered by ProjectVisibility. */
interface ProjectDirectory
{
    /** Visible project or null (never distinguishes hidden from missing). */
    public function find(Authenticatable $actor, string $projectId): ?ProjectSummary;

    /**
     * @return list<ProjectSummary> visible projects ordered by key
     */
    public function visible(Authenticatable $actor): array;
}
