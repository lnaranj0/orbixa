<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Modules\Access\Contracts\AccessDenied;
use Modules\IdentityTenancy\Contracts\MembershipChangeDenied;
use Modules\IdentityTenancy\Contracts\MembershipReferenceInvalid;
use Modules\Monitoring\Contracts\GrantNotFound;
use Modules\Monitoring\Contracts\GrantOperationDenied;
use Modules\Monitoring\Contracts\GrantRejected;
use Modules\Monitoring\Contracts\GrantVersionConflict;
use Modules\Monitoring\Contracts\MonitoringNotFound;
use Modules\Platform\Contracts\Http\InvalidCursor;
use Modules\Platform\Contracts\Idempotency\IdempotencyConflict;
use Modules\Platform\Contracts\Tenancy\TenantAccessDenied;
use Modules\Platform\Contracts\Validation\InputRejected;
use Modules\Platform\Presentation\Http\Middleware\AssignCorrelationId;
use Modules\Portfolio\Contracts\ProjectKeyUnavailable;
use Modules\Portfolio\Contracts\ProjectLinkNotFound;
use Modules\Portfolio\Contracts\ProjectMemberNotFound;
use Modules\Portfolio\Contracts\ProjectNotFound;
use Modules\Portfolio\Contracts\ProjectOperationDenied;
use Modules\Portfolio\Contracts\ProjectRuleViolation;
use Modules\Portfolio\Contracts\ProjectVersionConflict;
use Modules\Teams\Contracts\HierarchyViolation;
use Modules\Teams\Contracts\TeamMemberNotFound;
use Modules\Teams\Contracts\TeamNotFound;
use Modules\Teams\Contracts\TeamOperationDenied;
use Modules\Work\Contracts\WorkItemNotFound;
use Modules\Work\Contracts\WorkOperationDenied;
use Modules\Work\Contracts\WorkRelationNotFound;
use Modules\Work\Contracts\WorkRuleViolation;
use Modules\Work\Contracts\WorkVersionConflict;
use Modules\Workflow\Contracts\WorkflowNotFound;
use Modules\Workflow\Contracts\WorkflowOperationDenied;
use Modules\Workflow\Contracts\WorkflowRuleViolation;
use Modules\Workflow\Contracts\WorkflowVersionConflict;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(AssignCorrelationId::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // D3: anything outside the user's organizations is indistinguishable
        // from a missing resource (404); a resource of the active
        // organization without permission is 403.
        $exceptions->map(TenantAccessDenied::class, fn (TenantAccessDenied $e) => new NotFoundHttpException(previous: $e));
        $exceptions->map(InputRejected::class, fn (InputRejected $e) => new UnprocessableEntityHttpException($e->getMessage(), $e));
        $exceptions->map(InvalidCursor::class, fn (InvalidCursor $e) => new UnprocessableEntityHttpException($e->getMessage(), $e));
        $exceptions->map(IdempotencyConflict::class, fn (IdempotencyConflict $e) => new UnprocessableEntityHttpException($e->getMessage(), $e));
        $exceptions->map(AccessDenied::class, fn (AccessDenied $e) => new AccessDeniedHttpException(previous: $e));
        $exceptions->map(MembershipChangeDenied::class, fn (MembershipChangeDenied $e) => new AccessDeniedHttpException(previous: $e));
        $exceptions->map(MembershipReferenceInvalid::class, fn (MembershipReferenceInvalid $e) => new UnprocessableEntityHttpException($e->getMessage(), $e));
        $exceptions->map(TeamOperationDenied::class, fn (TeamOperationDenied $e) => new AccessDeniedHttpException(previous: $e));
        $exceptions->map(TeamNotFound::class, fn (TeamNotFound $e) => new NotFoundHttpException(previous: $e));
        $exceptions->map(TeamMemberNotFound::class, fn (TeamMemberNotFound $e) => new NotFoundHttpException(previous: $e));
        $exceptions->map(HierarchyViolation::class, fn (HierarchyViolation $e) => new UnprocessableEntityHttpException($e->getMessage(), $e));
        $exceptions->map(ProjectNotFound::class, fn (ProjectNotFound $e) => new NotFoundHttpException(previous: $e));
        $exceptions->map(ProjectOperationDenied::class, fn (ProjectOperationDenied $e) => new AccessDeniedHttpException(previous: $e));
        $exceptions->map(ProjectKeyUnavailable::class, fn (ProjectKeyUnavailable $e) => new UnprocessableEntityHttpException($e->getMessage(), $e));
        $exceptions->map(ProjectMemberNotFound::class, fn (ProjectMemberNotFound $e) => new NotFoundHttpException(previous: $e));
        $exceptions->map(ProjectLinkNotFound::class, fn (ProjectLinkNotFound $e) => new NotFoundHttpException(previous: $e));
        $exceptions->map(ProjectRuleViolation::class, fn (ProjectRuleViolation $e) => new UnprocessableEntityHttpException($e->getMessage(), $e));
        $exceptions->map(ProjectVersionConflict::class, fn (ProjectVersionConflict $e) => new ConflictHttpException($e->getMessage(), $e));

        $exceptions->map(WorkItemNotFound::class, fn (WorkItemNotFound $e) => new NotFoundHttpException(previous: $e));
        $exceptions->map(WorkRelationNotFound::class, fn (WorkRelationNotFound $e) => new NotFoundHttpException(previous: $e));
        $exceptions->map(WorkOperationDenied::class, fn (WorkOperationDenied $e) => new AccessDeniedHttpException(previous: $e));
        $exceptions->map(WorkRuleViolation::class, fn (WorkRuleViolation $e) => new UnprocessableEntityHttpException($e->getMessage(), $e));
        $exceptions->map(WorkVersionConflict::class, fn (WorkVersionConflict $e) => new ConflictHttpException($e->getMessage(), $e));

        $exceptions->map(MonitoringNotFound::class, fn (MonitoringNotFound $e) => new NotFoundHttpException(previous: $e));
        $exceptions->map(GrantNotFound::class, fn (GrantNotFound $e) => new NotFoundHttpException(previous: $e));
        $exceptions->map(GrantOperationDenied::class, fn (GrantOperationDenied $e) => new AccessDeniedHttpException(previous: $e));
        $exceptions->map(GrantRejected::class, fn (GrantRejected $e) => new UnprocessableEntityHttpException($e->getMessage(), $e));
        $exceptions->map(GrantVersionConflict::class, fn (GrantVersionConflict $e) => new ConflictHttpException($e->getMessage(), $e));

        $exceptions->map(WorkflowNotFound::class, fn (WorkflowNotFound $e) => new NotFoundHttpException(previous: $e));
        $exceptions->map(WorkflowOperationDenied::class, fn (WorkflowOperationDenied $e) => new AccessDeniedHttpException(previous: $e));
        $exceptions->map(WorkflowRuleViolation::class, fn (WorkflowRuleViolation $e) => new UnprocessableEntityHttpException($e->getMessage(), $e));
        $exceptions->map(WorkflowVersionConflict::class, fn (WorkflowVersionConflict $e) => new ConflictHttpException($e->getMessage(), $e));

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
