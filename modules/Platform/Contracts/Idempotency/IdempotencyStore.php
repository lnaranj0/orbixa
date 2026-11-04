<?php

namespace Modules\Platform\Contracts\Idempotency;

use Closure;
use Modules\Platform\Contracts\Tenancy\TenantContextRequired;

/**
 * Organization-scoped idempotency for repeated requests, webhook deliveries
 * and job retries. The key is reserved in the caller's tenant transaction,
 * so a failed operation leaves nothing behind and can be retried.
 */
interface IdempotencyStore
{
    /**
     * Runs $operation once per (organization, scope, key). Repeated calls with
     * the same request hash return the stored response without running it.
     * The response is stored as jsonb: replays are equal in content, but
     * object key order is normalized by PostgreSQL.
     *
     * @param  Closure(): array<string, mixed>  $operation  returns a JSON-serializable response
     * @return array<string, mixed>
     *
     * @throws IdempotencyConflict when the key was used with a different request
     * @throws TenantContextRequired outside TenantContext::runFor()
     */
    public function remember(string $scope, string $key, string $requestHash, Closure $operation): array;
}
