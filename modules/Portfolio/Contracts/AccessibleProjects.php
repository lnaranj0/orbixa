<?php

namespace Modules\Portfolio\Contracts;

/**
 * Result of the visibility predicate for the actor in the active
 * organization: either every project (organization-level projects.manage)
 * or an explicit id list. Consumers (03 work, 04 monitoring, APIs, exports)
 * must filter with it and never query projects without it.
 */
final readonly class AccessibleProjects
{
    /**
     * @param  list<string>  $ids
     */
    private function __construct(public bool $all, public array $ids) {}

    public static function everything(): self
    {
        return new self(true, []);
    }

    /**
     * @param  list<string>  $ids
     */
    public static function only(array $ids): self
    {
        return new self(false, array_values(array_unique($ids)));
    }

    /**
     * Membership in the visible set only. With $all it does NOT prove the id
     * exists in the active organization: use ProjectVisibility::canView() for
     * a single id, or filter queries that already run under RLS.
     */
    public function includes(string $projectId): bool
    {
        return $this->all || in_array($projectId, $this->ids, true);
    }
}
