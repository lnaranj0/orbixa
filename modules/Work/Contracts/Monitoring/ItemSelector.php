<?php

namespace Modules\Work\Contracts\Monitoring;

use InvalidArgumentException;

/**
 * Which items a trusted reader may return: the union of clauses. Each
 * clause is bounded by an explicit project list and selects all items of
 * those projects, items assigned (owner or collaborator) to given members,
 * or items linked to given teams. An empty selector selects nothing.
 *
 * Work knows nothing about monitoring grants; Monitoring builds selectors
 * from valid grants only.
 */
final readonly class ItemSelector
{
    public const ALL = 'all';

    public const ASSIGNEES = 'assignees';

    public const TEAMS = 'teams';

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    /**
     * @param  list<array{kind: string, project_ids: list<string>, ids: list<string>}>  $clauses
     */
    private function __construct(public array $clauses) {}

    public static function nothing(): self
    {
        return new self([]);
    }

    /**
     * @param  list<string>  $projectIds
     * @param  list<string>  $ids  memberships (ASSIGNEES) or teams (TEAMS); ignored for ALL
     */
    public function with(string $kind, array $projectIds, array $ids = []): self
    {
        if (! in_array($kind, [self::ALL, self::ASSIGNEES, self::TEAMS], true)) {
            throw new InvalidArgumentException('Unknown selector clause.');
        }
        $projectIds = self::uuids($projectIds);
        $ids = $kind === self::ALL ? [] : self::uuids($ids);
        if ($projectIds === [] || ($kind !== self::ALL && $ids === [])) {
            return $this; // selects nothing
        }

        return new self([...$this->clauses, ['kind' => $kind, 'project_ids' => $projectIds, 'ids' => $ids]]);
    }

    public function isEmpty(): bool
    {
        return $this->clauses === [];
    }

    /** Stable fingerprint, e.g. to bind cursors to the selector they were issued for. */
    public function fingerprint(): string
    {
        $clauses = $this->clauses;
        foreach ($clauses as &$clause) {
            sort($clause['project_ids']);
            sort($clause['ids']);
        }
        unset($clause);
        usort($clauses, fn (array $a, array $b) => strcmp(json_encode($a, JSON_THROW_ON_ERROR), json_encode($b, JSON_THROW_ON_ERROR)));

        return hash('sha256', json_encode($clauses, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  list<string>  $ids
     * @return list<string>
     */
    private static function uuids(array $ids): array
    {
        return array_values(array_unique(array_filter($ids, fn (string $id) => preg_match(self::UUID, $id) === 1)));
    }
}
