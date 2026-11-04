<?php

namespace Modules\Platform\Tests\Security\Database;

use Illuminate\Support\Str;
use Modules\Platform\Contracts\Idempotency\IdempotencyConflict;
use Modules\Platform\Contracts\Idempotency\IdempotencyStore;
use Modules\Platform\Contracts\Tenancy\TenantContextRequired;
use Modules\Platform\Tests\Security\SecurityTestCase;
use Modules\Platform\Tests\Security\Support\TenantFixtures;
use RuntimeException;

/**
 * Repeated processing is idempotent per organization (00 task 4).
 */
final class IdempotencyTest extends SecurityTestCase
{
    use TenantFixtures;

    private IdempotencyStore $store;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenants();
        $this->store = $this->app->make(IdempotencyStore::class);
        $this->key = (string) Str::uuid();
    }

    public function test_repeated_request_returns_the_stored_response_without_running_again(): void
    {
        $runs = 0;
        $operation = function () use (&$runs) {
            $runs++;

            return ['result' => 'done', 'run' => $runs];
        };

        $first = $this->tenant->runFor($this->actor, $this->orgA, fn () => $this->store->remember('test.scope', $this->key, $this->hash('a'), $operation));
        $second = $this->tenant->runFor($this->actor, $this->orgA, fn () => $this->store->remember('test.scope', $this->key, $this->hash('a'), $operation));

        $this->assertSame(1, $runs);
        // Stored as jsonb: same content, key order normalized by PostgreSQL.
        $this->assertEquals($first, $second);
    }

    public function test_reusing_a_key_with_a_different_request_is_rejected(): void
    {
        $this->tenant->runFor($this->actor, $this->orgA, fn () => $this->store->remember('test.scope', $this->key, $this->hash('a'), fn () => ['ok' => true]));

        $this->expectException(IdempotencyConflict::class);

        $this->tenant->runFor($this->actor, $this->orgA, fn () => $this->store->remember('test.scope', $this->key, $this->hash('b'), fn () => ['ok' => true]));
    }

    public function test_keys_are_scoped_per_organization(): void
    {
        $runs = 0;
        $operation = function () use (&$runs) {
            return ['run' => ++$runs];
        };

        $this->tenant->runFor($this->actor, $this->orgA, fn () => $this->store->remember('test.scope', $this->key, $this->hash('a'), $operation));
        $this->tenant->runFor($this->actor, $this->orgB, fn () => $this->store->remember('test.scope', $this->key, $this->hash('a'), $operation));

        $this->assertSame(2, $runs);
    }

    public function test_failed_operation_leaves_no_reservation_and_can_be_retried(): void
    {
        try {
            $this->tenant->runFor($this->actor, $this->orgA, fn () => $this->store->remember(
                'test.scope', $this->key, $this->hash('a'), fn () => throw new RuntimeException('transient')
            ));
        } catch (RuntimeException) {
            // expected
        }

        $result = $this->tenant->runFor($this->actor, $this->orgA, fn () => $this->store->remember(
            'test.scope', $this->key, $this->hash('a'), fn () => ['retried' => true]
        ));

        $this->assertSame(['retried' => true], $result);
    }

    public function test_requires_an_active_tenant_context(): void
    {
        $this->expectException(TenantContextRequired::class);

        $this->store->remember('test.scope', $this->key, $this->hash('a'), fn () => []);
    }

    private function hash(string $request): string
    {
        return hash('sha256', $request);
    }
}
