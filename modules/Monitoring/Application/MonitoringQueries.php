<?php

namespace Modules\Monitoring\Application;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Monitoring\Contracts\MonitoringNotFound;
use Modules\Monitoring\Infrastructure\GrantStore;
use Modules\Monitoring\Infrastructure\MonitoringAudit;
use Modules\Portfolio\Contracts\ProjectDirectory;
use Modules\Work\Contracts\Monitoring\MonitoredItem;
use Modules\Work\Contracts\Monitoring\MonitoredWorkReader;

/**
 * Read side of Monitoring (M6, M10). Every query starts from the viewer's
 * scope, recomputed per call; per-member and per-team views only narrow it
 * and answer 404 when no grant covers that member or team.
 */
final class MonitoringQueries
{
    public function __construct(
        private readonly MonitoringVisibility $visibility,
        private readonly MonitoredWorkReader $work,
        private readonly MonitoringAudit $audit,
        private readonly ProjectDirectory $projects,
    ) {}

    public function scope(Authenticatable $actor): MonitoringScope
    {
        return $this->visibility->scopeFor($actor);
    }

    /**
     * @param  array{assignee_membership_id?: string, team_ids?: list<string>}  $narrow
     * @return array{total: int, by_status: array<string, int>, by_priority: array<string, int>}
     */
    public function overview(Authenticatable $actor, MonitoringScope $scope, array $narrow = []): array
    {
        $this->audit->recordUse($actor, $scope->viewerMembershipId, $scope->auditedGrantIds);

        return $this->work->counts($scope->selector, $narrow);
    }

    /**
     * @param  array{status?: string, type?: string, priority?: string, assignee_membership_id?: string, team_ids?: list<string>}  $filters
     * @param  array{0: string, 1: string}|null  $after
     * @return list<MonitoredItem>
     */
    public function items(Authenticatable $actor, MonitoringScope $scope, array $filters, ?array $after, int $limit): array
    {
        $this->audit->recordUse($actor, $scope->viewerMembershipId, $scope->auditedGrantIds);

        return $this->work->page($scope->selector, $filters, $after, $limit);
    }

    /**
     * Flow metrics over the viewer's scope only (05 P8).
     *
     * @return array{throughput: int, lead_time_days: array{count: int, median: float|null, p85: float|null}, cycle_time_days: array{count: int, median: float|null, p85: float|null}, aging_days: array{count: int, median: float|null, p85: float|null}}
     */
    public function flowMetrics(Authenticatable $actor, MonitoringScope $scope, string $from, string $to): array
    {
        $this->audit->recordUse($actor, $scope->viewerMembershipId, $scope->auditedGrantIds);

        return $this->work->flowMetrics($scope->selector, $from, $to);
    }

    public function item(Authenticatable $actor, MonitoringScope $scope, string $itemId): MonitoredItem
    {
        $item = $this->work->find($scope->selector, $itemId) ?? throw MonitoringNotFound::make();
        $this->audit->recordUse($actor, $scope->viewerMembershipId, $scope->auditedGrantIds);
        // Restricted projects read by a non-member (implicit owner/admin scope) keep the Portfolio audit too.
        $this->projects->recordSensitiveAccess($actor, $item->projectId, 'monitoring.view');

        return $item;
    }

    /** Narrowing for /users/{member}; 404 unless a grant covers that member. */
    public function requireMember(MonitoringScope $scope, string $membershipId): string
    {
        if ($scope->isEmpty() || ! GrantStore::isUuid($membershipId) || ! $scope->coversMember($membershipId)) {
            throw MonitoringNotFound::make();
        }

        return $membershipId;
    }

    /** Narrowing for /teams/{team}; 404 unless a grant covers that team. */
    public function requireTeam(MonitoringScope $scope, string $teamId): string
    {
        if ($scope->isEmpty() || ! GrantStore::isUuid($teamId) || ! $scope->coversTeam($teamId)) {
            throw MonitoringNotFound::make();
        }

        return $teamId;
    }
}
