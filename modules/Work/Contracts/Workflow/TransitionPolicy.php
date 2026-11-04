<?php

namespace Modules\Work\Contracts\Workflow;

/**
 * 05 P2: the project's workflow as seen by Work. Work owns items and their
 * history; the Workflow module owns statuses and transitions and implements
 * this port (deptrac layer WorkWorkflowContracts). Always inside a tenant
 * context (RLS).
 */
interface TransitionPolicy
{
    /** Creates the project's default workflow if it has none (idempotent, race-safe). */
    public function ensureWorkflow(string $projectId): void;

    public function initialStatus(string $projectId): WorkflowStatus;

    /** An active (not archived) status of the project, or null. */
    public function status(string $projectId, string $statusId): ?WorkflowStatus;

    /**
     * Locks an active status FOR SHARE until commit and returns it re-read
     * (current WIP limit), or null when archived or unknown. Archiving takes
     * FOR UPDATE on the same row, so a move into a status cannot race its
     * archiving (05 review M1, M2).
     */
    public function lockForEntry(string $projectId, string $statusId): ?WorkflowStatus;

    /** First active status of the category by position, or null. */
    public function statusForCategory(string $projectId, string $category): ?WorkflowStatus;

    /** The enabled transition between two statuses, or null when not configured. */
    public function transition(string $projectId, string $fromStatusId, string $toStatusId): ?TransitionRule;

    /**
     * Enabled transitions leaving a status (for screens and APIs).
     *
     * @return list<TransitionRule>
     */
    public function transitionsFrom(string $projectId, string $fromStatusId): array;

    /**
     * @return list<WorkflowStatus> active statuses ordered by position
     */
    public function statusesOf(string $projectId): array;
}
