<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Modules\Access\Contracts\AccessDenied;
use Modules\IdentityTenancy\Contracts\MembershipChangeDenied;
use Modules\IdentityTenancy\Contracts\MembershipReferenceInvalid;
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

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
