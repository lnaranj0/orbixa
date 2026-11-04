<?php

namespace Modules\Platform\Presentation\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Modules\Platform\Infrastructure\Database\EphemeralTestDatabaseGuard;

/**
 * Empties module tables in the ephemeral test database. It never drops
 * objects and refuses to run unless APP_ENV=testing and the migrator
 * connection points at the sentinel-marked test database.
 */
final class ResetTestDatabaseCommand extends Command
{
    protected $signature = 'orbixa:test-db:reset';

    protected $description = 'Truncate module tables in the ephemeral PostgreSQL test database (guarded)';

    public function handle(DatabaseManager $db, EphemeralTestDatabaseGuard $guard, Repository $config): int
    {
        if (! $this->laravel->environment('testing')) {
            $this->error('Only available with APP_ENV=testing.');

            return self::FAILURE;
        }

        $connection = $db->connection((string) $config->get('database.migrator_connection'));
        $guard->assertSafe($connection);

        $tables = array_column($connection->select(
            "select format('%I.%I', schemaname, tablename) as qualified
               from pg_tables
              where schemaname not in ('pg_catalog', 'information_schema', 'public')
                and tableowner = current_user
              order by 1"
        ), 'qualified');

        if ($tables === []) {
            $this->info('No module tables to reset.');

            return self::SUCCESS;
        }

        $connection->statement('truncate '.implode(', ', $tables).' restrict');
        $this->info('Reset '.count($tables).' table(s) in the ephemeral test database.');

        return self::SUCCESS;
    }
}
