<?php

namespace Modules\Work\Contracts;

use RuntimeException;

/**
 * A business rule rejected the change (HTTP 422). Reference errors (PARENT,
 * LABEL, TEAM, DEPENDENCY) are the same for a missing, foreign or invisible
 * target, so they do not reveal what exists.
 */
final class WorkRuleViolation extends RuntimeException
{
    public const ARCHIVED = 'project is archived';

    public const PARENT = 'parent cannot be used';

    public const HIERARCHY_CYCLE = 'hierarchy cycle';

    public const DEPTH = 'maximum depth exceeded';

    public const TYPE = 'incompatible type';

    public const TRANSITION = 'transition not allowed';

    public const REASON = 'a reason is required';

    public const LABEL = 'label cannot be used';

    public const LABEL_LIMIT = 'too many labels';

    public const TEAM = 'team cannot be linked';

    public const TEAM_LIMIT = 'too many teams';

    public const ASSIGNEE_LIMIT = 'too many assignees';

    public const DEPENDENCY = 'item cannot be linked';

    public const DEPENDENCY_CYCLE = 'dependency cycle';

    public const DEPENDENCY_LIMIT = 'too many dependencies';

    public static function because(string $rule): self
    {
        return new self($rule);
    }
}
