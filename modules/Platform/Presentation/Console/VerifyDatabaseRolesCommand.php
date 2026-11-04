<?php

namespace Modules\Platform\Presentation\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Modules\Platform\Infrastructure\Database\PostgresRoles;
use Throwable;

/**
 * Read-only check of the configured connections, for deploys and health
 * checks outside the test suite: the runtime connection must be the
 * non-owner role without SUPERUSER/BYPASSRLS and without membership in the
 * migrator; the migrator must not be a superuser either.
 */
final class VerifyDatabaseRolesCommand extends Command
{
    protected $signature = 'orbixa:db:verify
        {--with-migrator : Also verify the migrator connection}
        {--with-relay : Also verify the outbox relay connection}';

    protected $description = 'Verify that database connections use the least-privilege Orbixa roles (read-only)';

    public function handle(DatabaseManager $db, Repository $config): int
    {
        $failures = $this->check($db, 'pgsql', PostgresRoles::runtime(), checkMembership: true);

        if ($this->option('with-migrator')) {
            $failures = [
                ...$failures,
                ...$this->check($db, (string) $config->get('database.migrator_connection'), PostgresRoles::migrator(), checkMembership: false),
            ];
        }

        if ($this->option('with-relay')) {
            $failures = [
                ...$failures,
                ...$this->check($db, (string) $config->get('database.relay_connection'), PostgresRoles::relay(), checkMembership: true),
            ];
        }

        foreach ($failures as $failure) {
            $this->error($failure);
        }

        if ($failures !== []) {
            return self::FAILURE;
        }

        $this->info('Database roles verified.');

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function check(DatabaseManager $db, string $connection, string $expectedRole, bool $checkMembership): array
    {
        try {
            $role = $db->connection($connection)->selectOne(
                "select current_user as name, rolsuper, rolbypassrls, rolcreaterole, rolcreatedb,
                        pg_has_role(current_user, ?, 'MEMBER') as member_of_migrator
                   from pg_roles where rolname = current_user",
                [PostgresRoles::migrator()]
            );
        } catch (Throwable) {
            return ["[{$connection}] could not connect or query the role catalog."];
        }

        $failures = [];

        if ($role === null || $role->name !== $expectedRole) {
            $failures[] = "[{$connection}] connected role is not {$expectedRole}.";
        }

        if ($role?->rolsuper || $role?->rolbypassrls || $role?->rolcreaterole || $role?->rolcreatedb) {
            $failures[] = "[{$connection}] role has SUPERUSER, BYPASSRLS, CREATEROLE or CREATEDB.";
        }

        if ($checkMembership && $role?->member_of_migrator) {
            $failures[] = "[{$connection}] role can assume the migrator role.";
        }

        return $failures;
    }
}
