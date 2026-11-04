<?php

namespace Modules\Platform\Infrastructure\Idempotency;

use Closure;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Modules\Platform\Contracts\Idempotency\IdempotencyConflict;
use Modules\Platform\Contracts\Idempotency\IdempotencyStore;
use Modules\Platform\Contracts\Tenancy\TenantContext;
use Modules\Platform\Contracts\Tenancy\TenantContextRequired;

/**
 * Reserves (organization, scope, key) with INSERT ... ON CONFLICT DO NOTHING
 * in the caller's transaction. A concurrent duplicate waits for the first
 * transaction: on commit it reads the stored response, on rollback it runs.
 */
final class PostgresIdempotencyStore implements IdempotencyStore
{
    private const TTL = '24 hours';

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly DatabaseManager $db,
    ) {}

    public function remember(string $scope, string $key, string $requestHash, Closure $operation): array
    {
        if ($scope === '' || strlen($scope) > 100 || $key === '' || strlen($key) > 200) {
            throw new InvalidArgumentException('Idempotency scope and key must be non-empty and bounded.');
        }

        if (preg_match('/^[0-9a-f]{64}$/', $requestHash) !== 1) {
            throw new InvalidArgumentException('Request hash must be a lowercase SHA-256 hex digest.');
        }

        $connection = $this->db->connection();

        if ($this->tenant->current() === null || $connection->transactionLevel() === 0) {
            throw TenantContextRequired::forOperation('Idempotent execution');
        }

        $reserved = $connection->selectOne(
            "insert into platform.idempotency_keys (organization_id, scope, key, request_hash, expires_at)
             values (platform.current_organization_id(), ?, ?, ?, now() + interval '".self::TTL."')
             on conflict (organization_id, scope, key) do nothing
             returning id",
            [$scope, $key, $requestHash]
        );

        if ($reserved === null) {
            $existing = $connection->selectOne(
                'select request_hash, response from platform.idempotency_keys where scope = ? and key = ?',
                [$scope, $key]
            );

            if ($existing === null || $existing->request_hash !== $requestHash || $existing->response === null) {
                throw IdempotencyConflict::differentRequest();
            }

            return json_decode($existing->response, true, flags: JSON_THROW_ON_ERROR);
        }

        $response = $operation();

        $connection->update(
            'update platform.idempotency_keys set response = ?::jsonb where id = ?',
            [json_encode($response, JSON_THROW_ON_ERROR), $reserved->id]
        );

        return $response;
    }
}
