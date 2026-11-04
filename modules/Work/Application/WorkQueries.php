<?php

namespace Modules\Work\Application;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Portfolio\Contracts\ProjectDirectory;
use Modules\Portfolio\Contracts\ProjectVisibility;
use Modules\Work\Infrastructure\ItemStore;

/**
 * Read side of Work (W5, W11). Every query starts from the project
 * visibility predicate; filters and sorting are applied afterwards.
 *
 * @phpstan-import-type ItemRow from ItemStore
 *
 * @phpstan-type Filters array{status?: string, type?: string, priority?: string, label_id?: string, parent_id?: string, assignee_membership_id?: string}
 * @phpstan-type Sort 'number'|'priority'|'updated_at'
 */
final class WorkQueries
{
    public function __construct(
        private readonly WorkGuard $guard,
        private readonly ItemStore $items,
        private readonly ProjectVisibility $visibility,
        private readonly ProjectDirectory $projects,
    ) {}

    /**
     * @param  Filters  $filters
     * @param  Sort  $sort
     * @param  array{0: string, 1: string}|null  $after
     * @return list<ItemRow>
     */
    public function backlog(Authenticatable $actor, string $projectId, array $filters, string $sort, ?array $after, int $limit): array
    {
        $project = $this->guard->project($actor, $projectId);

        return $this->items->page([$project->id], $filters, $sort, $after, $limit);
    }

    /**
     * "Mi trabajo": active assignments of the actor, only in projects the
     * actor can still see.
     *
     * @param  Filters  $filters
     * @param  Sort  $sort
     * @param  array{0: string, 1: string}|null  $after
     * @return list<ItemRow>
     */
    public function myWork(Authenticatable $actor, array $filters, string $sort, ?array $after, int $limit): array
    {
        $membershipId = $this->guard->context($actor);
        $accessible = $this->visibility->accessibleProjects($actor);
        if (! $accessible->all && $accessible->ids === []) {
            return [];
        }

        return $this->items->page($accessible->all ? null : $accessible->ids, ['assignee_membership_id' => $membershipId] + $filters, $sort, $after, $limit);
    }

    /**
     * Full detail; reading it through the organization role on a
     * restricted project is audited (D3).
     *
     * @return array{item: ItemRow, project_key: string, labels: list<array{id: string, name: string, color: string}>, assignments: list<array{membership_id: string, role: string, assigned_at: string}>, team_ids: list<string>, dependencies: array{blocked_by: list<array{id: string, key: string, status: string}>, blocks: list<array{id: string, key: string, status: string}>}}
     */
    public function detail(Authenticatable $actor, string $itemId): array
    {
        [$item, $project] = $this->guard->item($actor, $itemId);
        $this->projects->recordSensitiveAccess($actor, $project->id, 'work.view');

        return [
            'item' => $item,
            'project_key' => $project->key,
            'labels' => $this->items->labelsOfItem($item['id']),
            'assignments' => $this->items->assignmentsOf($item['id']),
            'team_ids' => $this->items->teamIdsOf($item['id']),
            'dependencies' => $this->items->dependenciesOf($item['id']),
        ];
    }

    /** Resolves a key like ORBX-12 to a visible item id, or null. */
    public function idForKey(Authenticatable $actor, string $key): ?string
    {
        $item = $this->items->findByKey($key);
        if ($item === null) {
            return null;
        }

        return $this->projects->reference($actor, $item['project_id']) === null ? null : $item['id'];
    }

    /**
     * @param  array{0: string, 1: string}|null  $after
     * @return list<array{id: string, type: string, actor_membership_id: string, changes: array<string, mixed>, occurred_at: string}>
     */
    public function activity(Authenticatable $actor, string $itemId, ?array $after, int $limit): array
    {
        [$item, $project] = $this->guard->item($actor, $itemId);
        $this->projects->recordSensitiveAccess($actor, $project->id, 'work.view');

        return $this->items->activity($item['id'], $after, $limit);
    }

    /**
     * @return list<array{id: string, name: string, color: string}>
     */
    public function labels(Authenticatable $actor, string $projectId): array
    {
        return $this->items->labelsOf($this->guard->project($actor, $projectId)->id);
    }

    public function sortValue(string $itemId, string $sort): string
    {
        return $this->items->sortValue($itemId, $sort);
    }
}
