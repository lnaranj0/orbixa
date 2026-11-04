<?php

namespace Modules\Platform\Infrastructure\Database;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use RuntimeException;

/**
 * Proves a connection points at the ephemeral test database: the database
 * name must match AND it must carry the sentinel comment that only the test
 * provisioning script sets. The development database never has it.
 */
final class EphemeralTestDatabaseGuard
{
    public function __construct(private readonly Repository $config) {}

    public function assertSafe(Connection $connection): void
    {
        $expected = (array) $this->config->get('database.ephemeral_test_database');

        $database = $connection->selectOne(
            "select current_database() as name, shobj_description(oid, 'pg_database') as sentinel
               from pg_database where datname = current_database()"
        );

        if ($database === null
            || $database->name !== ($expected['name'] ?? null)
            || $database->sentinel !== ($expected['sentinel'] ?? null)) {
            throw new RuntimeException(
                'Refusing to continue: the connection is not the ephemeral PostgreSQL test database.'
            );
        }
    }
}
