<?php

namespace Modules\Platform\Tests\Security;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Platform\Infrastructure\Database\EphemeralTestDatabaseGuard;
use Modules\Platform\Infrastructure\Database\PostgresRoles;
use Tests\TestCase;

/**
 * Base for tests that run against the real PostgreSQL test server.
 *
 * Every test first proves it is connected to the sentinel-marked ephemeral
 * database as the runtime role (no superuser, no BYPASSRLS). Tests commit
 * for real (no DatabaseTransactions) so connection reuse and RLS semantics
 * are exercised exactly as in production; each test uses fresh random
 * organization ids, so leftover rows never affect assertions.
 */
abstract class SecurityTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $connection = DB::connection();
        $this->app->make(EphemeralTestDatabaseGuard::class)->assertSafe($connection);

        $role = $connection->selectOne(
            'select current_user as name, rolsuper, rolbypassrls from pg_roles where rolname = current_user'
        );

        if ($role === null || $role->name !== PostgresRoles::runtime() || $role->rolsuper || $role->rolbypassrls) {
            $this->fail('Security suite must connect as the runtime role without SUPERUSER or BYPASSRLS.');
        }
    }

    protected function tearDown(): void
    {
        // Each test boots a fresh application; close its connections so roles
        // with a CONNECTION LIMIT (relay: 5) are not exhausted across tests.
        foreach (array_keys(DB::getConnections()) as $name) {
            DB::purge($name);
        }

        parent::tearDown();
    }

    /**
     * Runs a statement in its own transaction and asserts it fails with the
     * given SQLSTATE. The transaction is always rolled back.
     *
     * @param  Closure(Connection): mixed  $statement
     */
    protected function assertSqlState(string $expected, Closure $statement, ?string $connectionName = null): void
    {
        $connection = DB::connection($connectionName);
        $connection->beginTransaction();

        try {
            $statement($connection);
            $this->fail("Expected SQLSTATE {$expected}, but the statement succeeded.");
        } catch (QueryException $exception) {
            $this->assertSame($expected, $exception->errorInfo[0] ?? (string) $exception->getCode(), $exception->getMessage());
        } finally {
            $connection->rollBack();
        }
    }
}
