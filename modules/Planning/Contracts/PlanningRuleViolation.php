<?php

namespace Modules\Planning\Contracts;

use RuntimeException;

/** A planning rule rejected the change (HTTP 422). ITEM is the same for a missing, foreign or other-project item. */
final class PlanningRuleViolation extends RuntimeException
{
    public const CONTINUOUS = 'continuous projects do not use cycles';

    public const ACTIVE_EXISTS = 'the project already has an active cycle';

    public const CLOSED = 'the cycle is closed';

    public const STATE = 'the cycle is not in a state that allows this';

    public const ITEM = 'item cannot be planned';

    public const IN_ANOTHER_CYCLE = 'the item is already in an open cycle';

    public static function because(string $rule): self
    {
        return new self($rule);
    }
}
