<?php

namespace Modules\Monitoring\Application;

use Modules\Work\Contracts\Monitoring\ItemSelector;

/**
 * What a viewer may observe right now: the item selector built from valid
 * grants (or the implicit organization scope of owner/admin), plus the
 * people and teams those grants name, for the per-member and per-team
 * views. Empty for a viewer without grants.
 */
final readonly class MonitoringScope
{
    /**
     * @param  list<string>  $memberIds  members observed through user grants
     * @param  list<string>  $teamIds  teams observed through team grants (with descendants when granted)
     * @param  list<string>  $auditedGrantIds  restricted or organization grants whose use is audited
     */
    public function __construct(
        public string $viewerMembershipId,
        public ItemSelector $selector,
        public bool $organizationWide,
        public array $memberIds,
        public array $teamIds,
        public array $auditedGrantIds,
        public bool $implicit,
    ) {}

    public function isEmpty(): bool
    {
        return $this->selector->isEmpty();
    }

    /** Binds cursors to the viewer and to the exact grant set that issued them. */
    public function fingerprint(): string
    {
        return hash('sha256', $this->viewerMembershipId.'|'.$this->selector->fingerprint());
    }

    public function coversMember(string $membershipId): bool
    {
        return $this->organizationWide || in_array($membershipId, $this->memberIds, true);
    }

    public function coversTeam(string $teamId): bool
    {
        return $this->organizationWide || in_array($teamId, $this->teamIds, true);
    }
}
