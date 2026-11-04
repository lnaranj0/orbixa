<?php

namespace Modules\Portfolio\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Read access to projects, always filtered by ProjectVisibility. Listings
 * omit the description of restricted projects the actor sees only through
 * the organization role; the full detail is find(), which is audited (D3).
 */
interface ProjectDirectory
{
    /** Visible project or null (never distinguishes hidden from missing). */
    public function find(Authenticatable $actor, string $projectId): ?ProjectSummary;

    /**
     * @return list<ProjectSummary> visible projects ordered by key
     */
    public function visible(Authenticatable $actor): array;

    /**
     * Keyset page ordered by (key, id); filters apply after visibility.
     *
     * @param  array{status?: string, type?: string, confidentiality?: string}  $filters
     * @param  array{0: string, 1: string}|null  $after  [key, id] of the previous page's last row
     * @return list<ProjectSummary>
     */
    public function page(Authenticatable $actor, array $filters, ?array $after, int $limit): array;

    /**
     * Visible project for another module's use case (Work), or null. Not
     * audited by itself: callers that expose project content call
     * recordSensitiveAccess().
     */
    public function reference(Authenticatable $actor, string $projectId): ?ProjectReference;

    /**
     * D3: records audit.sensitive_access.granted when the project is
     * restricted and the actor sees it only through the organization role
     * (deduplicated per actor, project and operation).
     */
    public function recordSensitiveAccess(Authenticatable $actor, string $projectId, string $operation): void;
}
