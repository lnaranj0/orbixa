<?php

namespace Modules\Work\Contracts\Monitoring;

/**
 * Read-only projection of a work item for monitoring (M6, M7). It carries
 * no assignees, team links, dependencies or activity: nothing that could
 * reveal people or items outside the selector.
 */
final readonly class MonitoredItem
{
    public function __construct(
        public string $id,
        public string $key,
        public string $projectId,
        public string $type,
        public string $title,
        public string $status,
        public string $priority,
        public ?string $dueOn,
        public ?string $completedAt,
        public string $updatedAt,
        public ?string $description = null,
    ) {}
}
