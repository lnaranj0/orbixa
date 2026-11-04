<?php

namespace Modules\Portfolio\Contracts;

use RuntimeException;

/**
 * Business rule rejected the change (HTTP 422). TEAM is deliberately the
 * same for a missing, foreign, archived or unmanageable team the actor does
 * not belong to, so it does not reveal which teams exist.
 */
final class ProjectRuleViolation extends RuntimeException
{
    public const ARCHIVED = 'project is archived';

    public const TEAM = 'team cannot be linked';

    public static function because(string $rule): self
    {
        return new self($rule);
    }
}
