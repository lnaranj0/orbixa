<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Platform\Infrastructure\Database\PostgresRoles;

/**
 * Explicit, individually reviewed privileges of the runtime role on the
 * global (non-tenant) Laravel tables. No default privileges are used for
 * tables: every new table must grant what it needs in its own migration.
 *
 * Must run as the owner role (orbixa_migrator). On the development database
 * the existing tables are transferred to that role first by
 * database/provisioning/03_transfer_existing_ownership.sql.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> */
    private const TABLE_PRIVILEGES = [
        'migrations' => [],                                       // schema history: owner only
        'users' => ['SELECT', 'INSERT'],                          // global identity; UPDATE per column below; no DELETE yet
        'password_reset_tokens' => ['SELECT', 'INSERT', 'DELETE'], // broker deletes + inserts, never updates
        'sessions' => ['SELECT', 'INSERT', 'UPDATE', 'DELETE'],   // database session driver incl. GC
        'cache' => [],                                            // cache lives in Redis
        'cache_locks' => [],                                      // locks live in Redis
        'jobs' => [],                                             // queues live in Redis
        'job_batches' => [],                                      // granted when batching is adopted
        'failed_jobs' => ['SELECT', 'INSERT', 'DELETE'],          // failed driver: record, list, forget
    ];

    /**
     * Column-level UPDATE: the runtime can never rewrite primary keys or
     * created_at.
     *
     * @var array<string, list<string>>
     */
    private const COLUMN_UPDATES = [
        'users' => ['name', 'email', 'email_verified_at', 'password', 'remember_token', 'updated_at'],
    ];

    /** @var array<string, list<string>> */
    private const SEQUENCE_PRIVILEGES = [
        'migrations_id_seq' => [],
        'users_id_seq' => ['USAGE'],
        'jobs_id_seq' => [],
        'failed_jobs_id_seq' => ['USAGE'],
    ];

    public function up(): void
    {
        $runtime = PostgresRoles::runtime();

        foreach (self::TABLE_PRIVILEGES as $table => $privileges) {
            DB::statement("revoke all on table public.{$table} from public, {$runtime}");

            if ($privileges !== []) {
                DB::statement('grant '.implode(', ', $privileges)." on table public.{$table} to {$runtime}");
            }
        }

        foreach (self::COLUMN_UPDATES as $table => $columns) {
            DB::statement('grant update ('.implode(', ', $columns).") on table public.{$table} to {$runtime}");
        }

        foreach (self::SEQUENCE_PRIVILEGES as $sequence => $privileges) {
            DB::statement("revoke all on sequence public.{$sequence} from public, {$runtime}");

            if ($privileges !== []) {
                DB::statement('grant '.implode(', ', $privileges)." on sequence public.{$sequence} to {$runtime}");
            }
        }
    }

    public function down(): void
    {
        $runtime = PostgresRoles::runtime();

        foreach (array_keys(self::TABLE_PRIVILEGES) as $table) {
            DB::statement("revoke all on table public.{$table} from {$runtime}");
        }

        foreach (array_keys(self::SEQUENCE_PRIVILEGES) as $sequence) {
            DB::statement("revoke all on sequence public.{$sequence} from {$runtime}");
        }
    }
};
