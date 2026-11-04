<?php

namespace Modules\Workflow\Domain;

/**
 * The default workflow every project starts with: the base flow of
 * docs/WORKFLOWS.md (03 W3), with review -> done requiring work.approve
 * (05 D-A). Migration 2026_11_07_000200 seeded the same definition.
 */
final class DefaultWorkflow
{
    /** @var list<array{name: string, category: string, position: int, initial: bool}> */
    public const STATUSES = [
        ['name' => 'Backlog', 'category' => 'backlog', 'position' => 0, 'initial' => true],
        ['name' => 'Listo', 'category' => 'ready', 'position' => 1, 'initial' => false],
        ['name' => 'En curso', 'category' => 'active', 'position' => 2, 'initial' => false],
        ['name' => 'En revisión', 'category' => 'review', 'position' => 3, 'initial' => false],
        ['name' => 'Hecho', 'category' => 'done', 'position' => 4, 'initial' => false],
        ['name' => 'Cancelado', 'category' => 'canceled', 'position' => 5, 'initial' => false],
    ];

    /** @var list<array{0: string, 1: string, 2: bool, 3: string}> [from, to, requires reason, permission] */
    public const TRANSITIONS = [
        ['backlog', 'ready', false, 'work.transition'],
        ['ready', 'active', false, 'work.transition'],
        ['active', 'review', false, 'work.transition'],
        ['review', 'active', false, 'work.transition'],
        ['review', 'done', false, 'work.approve'],
        ['done', 'active', true, 'work.transition'],
        ['backlog', 'canceled', true, 'work.transition'],
        ['ready', 'canceled', true, 'work.transition'],
        ['active', 'canceled', true, 'work.transition'],
        ['review', 'canceled', true, 'work.transition'],
        ['canceled', 'backlog', true, 'work.transition'],
    ];
}
