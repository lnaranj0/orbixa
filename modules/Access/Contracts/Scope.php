<?php

namespace Modules\Access\Contracts;

use InvalidArgumentException;

/**
 * A resource a permission can be scoped to (D4): a team or a project of the
 * ACTIVE organization.
 */
final readonly class Scope
{
    public const TEAM = 'team';

    public const PROJECT = 'project';

    private function __construct(public string $type, public string $id) {}

    public static function team(string $teamId): self
    {
        return new self(self::TEAM, self::uuid($teamId));
    }

    public static function project(string $projectId): self
    {
        return new self(self::PROJECT, self::uuid($projectId));
    }

    private static function uuid(string $id): string
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id) !== 1) {
            throw new InvalidArgumentException('Scope id must be a lowercase UUID.');
        }

        return $id;
    }
}
