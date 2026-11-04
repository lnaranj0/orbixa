<?php

namespace Modules\Workflow\Contracts;

use RuntimeException;

/** A workflow invariant rejected the change (HTTP 422). */
final class WorkflowRuleViolation extends RuntimeException
{
    public const NAME_TAKEN = 'status name already used';

    public const INITIAL = 'the initial status cannot be archived';

    public const IN_USE = 'the status still has items';

    public const LAST_DONE = 'the workflow needs an active done status';

    public const DONE_APPROVAL = 'transitions into done require work.approve';

    public const DUPLICATE = 'the transition already exists';

    public const STATUS = 'status cannot be used';

    public const LIMIT = 'too many statuses or transitions';

    public static function because(string $rule): self
    {
        return new self($rule);
    }
}
