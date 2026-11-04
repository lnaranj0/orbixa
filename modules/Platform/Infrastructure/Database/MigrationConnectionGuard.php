<?php

namespace Modules\Platform\Infrastructure\Database;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

/**
 * Aborts schema-changing Artisan commands unless they explicitly target the
 * migrator connection and that connection really is the non-superuser,
 * non-BYPASSRLS owner role. Listens on CommandStarting because migrate
 * creates the migrations table before MigrationsStarted fires.
 */
final class MigrationConnectionGuard
{
    private const GUARDED_COMMANDS = [
        'migrate',
        'migrate:install',
        'migrate:status',
        'migrate:rollback',
        'migrate:reset',
        'migrate:refresh',
        'migrate:fresh',
        'db:seed',
        'db:wipe',
        'schema:dump',
    ];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Repository $config,
        private readonly EphemeralTestDatabaseGuard $testDatabase,
        private readonly Application $app,
    ) {}

    public function handle(CommandStarting $event): void
    {
        if (! in_array($event->command, self::GUARDED_COMMANDS, true)) {
            return;
        }

        $expected = (string) $this->config->get('database.migrator_connection');
        $requested = $event->input->getParameterOption('--database', null, true);

        if ($requested !== $expected) {
            throw new RuntimeException(sprintf(
                '"%s" must run with --database=%s (owner role). Refusing to use the runtime connection.',
                $event->command,
                $expected,
            ));
        }

        $this->assertMigratorRole($expected);

        // `--env=testing` silently falls back to .env when .env.testing is
        // missing; never let that reach the development database.
        if ($this->app->environment('testing')) {
            $this->testDatabase->assertSafe($this->db->connection($expected));
        }
    }

    private function assertMigratorRole(string $connectionName): void
    {
        $role = $this->db->connection($connectionName)->selectOne(
            'select current_user as name, rolsuper, rolbypassrls from pg_roles where rolname = current_user'
        );

        if ($role === null
            || $role->name !== PostgresRoles::migrator()
            || $role->rolsuper
            || $role->rolbypassrls) {
            throw new RuntimeException(
                'Migration connection is not the expected owner role, or it is a superuser / BYPASSRLS role.'
            );
        }
    }
}
