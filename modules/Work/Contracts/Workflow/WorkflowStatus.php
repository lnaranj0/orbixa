<?php

namespace Modules\Work\Contracts\Workflow;

/** A configured status of a project's workflow; category is one of the global six. */
final readonly class WorkflowStatus
{
    public function __construct(
        public string $id,
        public string $name,
        public string $category,
        public int $position,
        public ?int $wipLimit,
    ) {}
}
