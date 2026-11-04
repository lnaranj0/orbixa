<?php

namespace Modules\IdentityTenancy\Infrastructure\Jobs;

use Closure;
use InvalidArgumentException;
use Modules\IdentityTenancy\Contracts\TenantAwareJob;
use Modules\IdentityTenancy\Infrastructure\Persistence\User;
use Modules\Platform\Contracts\Tenancy\OrganizationId;
use Modules\Platform\Contracts\Tenancy\TenantAccessDenied;
use Modules\Platform\Contracts\Tenancy\TenantContext;

/**
 * Command-bus pipe (queued jobs pass through it via CallQueuedHandler):
 * re-resolves the actor and runs TenantAwareJob handlers inside
 * TenantContext::runFor, which re-verifies the membership at execution
 * time. Denial marks the queued job as failed (no retries); without a queue
 * job (dispatchNow) the denial is thrown.
 *
 * The handler runs inside the tenant transaction: tenant-aware jobs should
 * be short units of work.
 */
final class RunTenantAwareJobs
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function handle(object $command, Closure $next): mixed
    {
        if (! $command instanceof TenantAwareJob) {
            return $next($command);
        }

        try {
            $scope = $command->tenantScope();
            $organization = OrganizationId::fromString($scope->organizationId);
            $actor = $this->actor($scope->actorUserId);

            return $this->tenant->runFor($actor, $organization, fn () => $next($command));
        } catch (TenantAccessDenied|InvalidArgumentException $denied) {
            $denied = $denied instanceof TenantAccessDenied ? $denied : TenantAccessDenied::forActor();

            if (property_exists($command, 'job') && $command->job !== null && method_exists($command, 'fail')) {
                $command->fail($denied);

                return null;
            }

            throw $denied;
        }
    }

    private function actor(string $userId): User
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $userId) !== 1) {
            throw TenantAccessDenied::forActor();
        }

        return User::query()->find($userId) ?? throw TenantAccessDenied::forActor();
    }
}
