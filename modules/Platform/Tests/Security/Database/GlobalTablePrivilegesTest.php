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
        'sessions' => ['SELECT', 'INSERT', 'UPDATE', 'DELETE'],
        'cache' => [],
        'cache_locks' => [],
        'jobs' => [],
        'job_batches' => [],
        'failed_jobs' => ['SELECT', 'INSERT', 'DELETE'],
        // 06 G3: dedup register only; insert and look up, never update or delete.
        'integrations.webhook_deliveries' => ['INSERT'],
    ];

    /** @var array<string, list<string>> */
    private const EXPECTED_SEQUENCES = [
        'migrations_id_seq' => [],
        'jobs_id_seq' => [],
        'failed_jobs_id_seq' => ['USAGE'],
    ];

    public function test_each_global_table_grants_exactly_the_reviewed_privileges(): void
    {
        foreach (self::EXPECTED_TABLES as $table => $expected) {
            foreach (self::PRIVILEGES as $privilege) {
                $granted = DB::selectOne(
                    'select has_table_privilege(current_user, ?, ?) as granted',
                    [str_contains($table, '.') ? $table : "public.{$table}", $privilege]
                )->granted;

                $this->assertSame(
                    in_array($privilege, $expected, true),
                    $granted,
                    "Unexpected {$privilege} privilege on public.{$table}"
                );
            }
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

        $public = array_values(array_filter(array_keys(self::EXPECTED_TABLES), fn (string $table) => ! str_contains($table, '.')));
        $this->assertEqualsCanonicalizing($public, $tables);
    }
}
