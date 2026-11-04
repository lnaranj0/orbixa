<?php

namespace Modules\Work\Contracts\Workflow;

/**
 * A configured transition. requiredPermission only RESTRICTS what WorkGuard
 * already allows (work.transition or work.approve); it can never widen it.
 */
final readonly class TransitionRule
{
    public function __construct(
        public WorkflowStatus $to,
        public bool $requiresReason,
        public bool $checkBlockers,
        public string $requiredPermission,
    ) {}
}
