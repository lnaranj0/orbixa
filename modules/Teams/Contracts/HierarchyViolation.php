<?php

namespace Modules\Teams\Contracts;

use RuntimeException;

/**
 * Rejected hierarchy change (HTTP 422). Messages never reveal whether a
 * referenced team exists in another organization.
 */
final class HierarchyViolation extends RuntimeException
{
    public const CYCLE = 'The team cannot be placed under itself or one of its subteams.';

    public const DEPTH = 'The hierarchy cannot exceed 5 levels.';

    public const PARENT = 'The parent team is not an active team of this organization.';

    public const ACTIVE_CHILDREN = 'A team with active subteams cannot be archived.';

    public const ARCHIVED = 'Archived teams cannot change.';

    public static function because(string $message): self
    {
        return new self($message);
    }
}
