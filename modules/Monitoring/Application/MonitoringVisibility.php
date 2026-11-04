<?php

namespace Modules\Monitoring\Application;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Access\Contracts\Permission;
use Modules\IdentityTenancy\Contracts\CurrentMembership;
use Modules\IdentityTenancy\Contracts\MembershipLookup;
use Modules\Monitoring\Infrastructure\GrantStore;
use Modules\Platform\Contracts\Tenancy\TenantContext;
use Modules\Platform\Contracts\Tenancy\TenantContextRequired;
use Modules\Portfolio\Contracts\Catalog\ProjectCatalog;
use Modules\Teams\Contracts\TeamDirectory;
use Modules\Work\Contracts\Monitoring\ItemSelector;

/**
 * The single monitoring visibility predicate (M4, M6, M8). Deny by
 * default: without monitoring.view, or without a valid grant, the scope is
 * empty. Each grant contributes its own projects (standard, plus restricted
 * only for restricted grants); scopes are unioned clause by clause and never
 * mixed with the viewer's own project visibility. Owner/admin
 * (organization-level projects.manage) observe the whole organization, as
 * they already can through ProjectVisibility.
 */
final class MonitoringVisibility
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly CurrentMembership $memberships,
        private readonly MembershipLookup $lookup,
        private readonly Gate $gate,
        private readonly GrantStore $grants,
        private readonly ProjectCatalog $projects,
        private readonly TeamDirectory $teams,
    ) {}

    public function scopeFor(Authenticatable $actor): MonitoringScope
    {
        $this->tenant->current() ?? throw TenantContextRequired::forOperation('Monitoring');
        $viewer = $this->memberships->idFor($actor);
        if ($viewer === null || ! $this->allows($actor, Permission::MonitoringView)) {
            return new MonitoringScope($viewer ?? '', ItemSelector::nothing(), false, [], [], [], false);
        }

        $catalog = $this->projects->idsByConfidentiality();
        $everything = [...$catalog['standard'], ...$catalog['restricted']];

        if ($this->allows($actor, Permission::ProjectsManage)) {
            $all = ItemSelector::nothing()->with(ItemSelector::ALL, $everything);

            return new MonitoringScope($viewer, $all, true, [], [], [], true, $all);
        }

        $selector = ItemSelector::nothing();
        $metrics = ItemSelector::nothing();
        $organizationWide = false;
        $members = [];
        $teams = [];
        $audited = [];
        foreach ($this->grants->validFor($viewer) as $grant) {
            $projects = $grant['visibility_level'] === 'restricted' ? $everything : $catalog['standard'];

            if ($grant['scope_type'] === 'organization') {
                $selector = $selector->with(ItemSelector::ALL, $projects);
                $metrics = $metrics->with(ItemSelector::ALL, $projects);
                $organizationWide = true;
            } elseif ($grant['scope_type'] === 'user') {
                $target = (string) $grant['target_membership_id'];
                if (! $this->lookup->lockActive($target)) {
                    continue; // the observed member left: the grant shows nothing
                }
                $selector = $selector->with(ItemSelector::ASSIGNEES, $projects, [$target]);
                $members[] = $target;
            } else {
                $teamIds = $this->activeTeams((string) $grant['target_team_id'], $grant['include_descendants']);
                $selector = $selector->with(ItemSelector::TEAMS, $projects, $teamIds);
                $metrics = $metrics->with(ItemSelector::TEAMS, $projects, $teamIds);
                array_push($teams, ...$teamIds);
            }

            if ($grant['visibility_level'] === 'restricted' || $grant['scope_type'] === 'organization') {
                $audited[] = $grant['id'];
            }
        }

        return new MonitoringScope(
            $viewer, $selector, $organizationWide,
            array_values(array_unique($members)), array_values(array_unique($teams)), $audited, false, $metrics,
        );
    }

    /**
     * The team (if active) and, when granted, its active descendants,
     * computed on every read so moves and archives apply immediately.
     *
     * @return list<string>
     */
    private function activeTeams(string $teamId, bool $includeDescendants): array
    {
        if (! $this->teams->isActive($teamId)) {
            return [];
        }

        return $includeDescendants ? [$teamId, ...$this->teams->activeDescendantsOf($teamId)] : [$teamId];
    }

    private function allows(Authenticatable $actor, Permission $permission): bool
    {
        return $this->gate->forUser($actor)->allows($permission->value);
    }
}
