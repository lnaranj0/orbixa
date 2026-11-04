<?php

namespace Modules\Platform\Tests\Security\Database;

use Illuminate\Support\Facades\DB;
use Modules\Platform\Tests\Security\SecurityTestCase;

/**
 * Individually reviewed privileges of the runtime role on each global
 * (non-tenant) table. Kept as an independent literal on purpose: a change to
 * the migration must be mirrored here and reviewed.
 */
final class GlobalTablePrivilegesTest extends SecurityTestCase
{
    private const PRIVILEGES = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'TRUNCATE', 'REFERENCES', 'TRIGGER'];

    /** @var array<string, list<string>> */
    private const EXPECTED_TABLES = [
        'migrations' => [],
        'users' => ['SELECT', 'INSERT'],
        'password_reset_tokens' => ['SELECT', 'INSERT', 'DELETE'],
        'sessions' => ['SELECT', 'INSERT', 'UPDATE', 'DELETE'],
        'cache' => [],
        'cache_locks' => [],
        'jobs' => [],
        'job_batches' => [],
        'failed_jobs' => ['SELECT', 'INSERT', 'DELETE'],
    ];

    /** @var array<string, list<string>> */
    private const EXPECTED_SEQUENCES = [
        'migrations_id_seq' => [],
        'users_id_seq' => ['USAGE'],
        'jobs_id_seq' => [],
        'failed_jobs_id_seq' => ['USAGE'],
    ];

    public function test_each_global_table_grants_exactly_the_reviewed_privileges(): void
    {
        foreach (self::EXPECTED_TABLES as $table => $expected) {
            foreach (self::PRIVILEGES as $privilege) {
                $granted = DB::selectOne(
                    'select has_table_privilege(current_user, ?, ?) as granted',
                    ["public.{$table}", $privilege]
                )->granted;

                $this->assertSame(
                    in_array($privilege, $expected, true),
                    $granted,
                    "Unexpected {$privilege} privilege on public.{$table}"
                );
            }
        }
    }

    public function test_users_update_is_limited_to_reviewed_columns(): void
    {
        $updatable = ['name', 'email', 'email_verified_at', 'password', 'remember_token', 'updated_at'];

        $columns = DB::select(
            "select column_name, has_column_privilege(current_user, 'public.users', column_name, 'UPDATE') as granted
               from information_schema.columns
              where table_schema = 'public' and table_name = 'users'"
        );

        $this->assertNotEmpty($columns);

        foreach ($columns as $column) {
            $this->assertSame(
                in_array($column->column_name, $updatable, true),
                $column->granted,
                "Unexpected UPDATE privilege on public.users.{$column->column_name}"
            );
        }
    }

    public function test_each_global_sequence_grants_exactly_the_reviewed_privileges(): void
    {
        foreach (self::EXPECTED_SEQUENCES as $sequence => $expected) {
            foreach (['USAGE', 'SELECT', 'UPDATE'] as $privilege) {
                $granted = DB::selectOne(
                    'select has_sequence_privilege(current_user, ?, ?) as granted',
                    ["public.{$sequence}", $privilege]
                )->granted;

                $this->assertSame(
                    in_array($privilege, $expected, true),
                    $granted,
                    "Unexpected {$privilege} privilege on sequence public.{$sequence}"
                );
            }
        }
    }

    public function test_no_unreviewed_table_exists_in_public(): void
    {
        $tables = array_column(DB::select(
            "select tablename from pg_tables where schemaname = 'public' order by 1"
        ), 'tablename');

        $this->assertEqualsCanonicalizing(array_keys(self::EXPECTED_TABLES), $tables);
    }
}
