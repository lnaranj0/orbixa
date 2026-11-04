<?php

namespace Modules\Platform\Infrastructure\Database;

use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

/**
 * Role names come from configuration and end up in DDL (GRANT ... TO role),
 * where they cannot be bound as parameters. Only plain lowercase identifiers
 * are accepted, so they are safe to interpolate.
 */
final class PostgresRoles
{
    public static function runtime(): string
    {
        return self::identifier((string) Config::get('database.connections.pgsql.username'));
    }

    public static function migrator(): string
    {
        $connection = (string) Config::get('database.migrator_connection');

        return self::identifier((string) Config::get("database.connections.{$connection}.username"));
    }

    public static function relay(): string
    {
        $connection = (string) Config::get('database.relay_connection');

        return self::identifier((string) Config::get("database.connections.{$connection}.username"));
    }

    public static function membershipReader(): string
    {
        return self::identifier((string) Config::get('database.membership_reader_role'));
    }

    public static function identifier(string $name): string
    {
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $name) !== 1) {
            throw new InvalidArgumentException('Database role name must be a plain lowercase identifier.');
        }

        return $name;
    }
}
