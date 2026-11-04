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
}
