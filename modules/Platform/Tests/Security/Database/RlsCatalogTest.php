<?php

namespace Modules\Platform\Tests\Security\Database;

use Illuminate\Support\Facades\DB;
use Modules\Platform\Infrastructure\Database\PostgresRoles;
use Modules\Platform\Tests\Security\SecurityTestCase;

/**
 * Catalog-wide invariants: every tenant table follows the RLS template and
 * every non-tenant table is on the reviewed allowlist.
 */
final class RlsCatalogTest extends SecurityTestCase
{
    /** Global tables without organization_id, reviewed individually. */
    private const GLOBAL_TABLES = [
        'public.migrations',
        'identity.users',
        'identity.password_reset_tokens',
        'public.sessions',
        'public.cache',
        'public.cache_locks',
        'public.jobs',
        'public.job_batches',
        'public.failed_jobs',
    ];

    /**
     * The only policies allowed besides the runtime policy (option A relay):
     * table => policy => [command, role, USING, WITH CHECK] (whitespace-free).
     *
     * @var array<string, array<string, array{string, string, string, string|null}>>
     */
    private const REVIEWED_EXTRA_POLICIES = [
        'events.domain_outbox' => [
            'relay_read' => ['SELECT', 'orbixa_relay', 'true', null],
            'relay_mark_published' => ['UPDATE', 'orbixa_relay', '(published_atISNULL)', 'true'],
        ],
        // 01, option A: the NOLOGIN owner of the membership functions.
        'identity.organizations' => [
            'membership_reader_read' => ['SELECT', 'orbixa_membership_reader', 'true', null],
        ],
        'identity.organization_memberships' => [
            'membership_reader_read' => ['SELECT', 'orbixa_membership_reader', 'true', null],
        ],
    ];

    /**
     * The only SECURITY DEFINER functions allowed (01, option A), with their
     * exact owner and pinned search_path.
     *
     * @var array<string, array{string, string}>
     */
    private const REVIEWED_SECURITY_DEFINER_FUNCTIONS = [
        'identity.has_active_membership(uuid,uuid)' => ['orbixa_membership_reader', '{"search_path=pg_catalog, pg_temp"}'],
        'identity.organizations_for_user(uuid)' => ['orbixa_membership_reader', '{"search_path=pg_catalog, pg_temp"}'],
    ];

    public function test_every_tenant_table_follows_the_rls_template(): void
    {
        $tables = $this->tenantTables();

        $this->assertNotEmpty($tables, 'Expected at least platform.tenant_probe.');

        foreach ($tables as $table) {
            $this->assertTrue($table->rls, "{$table->qualified} must enable RLS");
            $this->assertTrue($table->forced, "{$table->qualified} must FORCE RLS");

            $policies = DB::select(
                'select policyname, permissive, roles::text[] as roles, cmd, qual, with_check
                   from pg_policies where schemaname = ? and tablename = ?',
                [$table->schema, $table->name]
            );

            // Any extra permissive policy is OR-ed in and widens access: only the
            // reviewed, named exceptions are tolerated, with their exact expressions.
            $extras = self::REVIEWED_EXTRA_POLICIES[$table->qualified] ?? [];
            $runtimePolicies = [];

            foreach ($policies as $candidate) {
                if (! isset($extras[$candidate->policyname])) {
                    $runtimePolicies[] = $candidate;

                    continue;
                }

                [$cmd, $role, $using, $check] = $extras[$candidate->policyname];
                $this->assertSame('PERMISSIVE', $candidate->permissive);
                $this->assertSame($cmd, $candidate->cmd, "{$table->qualified}.{$candidate->policyname} command");
                $this->assertSame('{'.$role.'}', $candidate->roles, "{$table->qualified}.{$candidate->policyname} role");
                $this->assertSame($using, preg_replace('/\s+/', '', (string) $candidate->qual));
                $this->assertSame($check, $candidate->with_check === null ? null : preg_replace('/\s+/', '', $candidate->with_check));
            }

            $this->assertCount(count($extras), array_filter($policies, fn ($p) => isset($extras[$p->policyname])), "{$table->qualified} is missing a reviewed policy");
            $this->assertCount(1, $runtimePolicies, "{$table->qualified} must have exactly one runtime policy");
            $policy = $runtimePolicies[0];

            $this->assertSame('PERMISSIVE', $policy->permissive);
            $this->assertSame('ALL', $policy->cmd);
            $this->assertSame('{'.PostgresRoles::runtime().'}', $policy->roles, "{$table->qualified} policy must target only the runtime role");
            $this->assertNotNull($policy->qual, "{$table->qualified} policy needs USING");
            $this->assertSame($policy->qual, $policy->with_check, "{$table->qualified} USING and WITH CHECK must be identical");
            $this->assertSame(
                '(organization_id=(SELECTplatform.current_organization_id()AScurrent_organization_id))',
                preg_replace('/\s+/', '', (string) $policy->qual),
                "{$table->qualified} policy must use the canonical tenant predicate"
            );
        }
    }

    public function test_every_tenant_table_makes_organization_id_immutable(): void
    {
        foreach ($this->tenantTables() as $table) {
            $trigger = DB::selectOne(<<<'SQL'
                select count(*) as total
                  from pg_trigger t
                  join pg_attribute a on a.attrelid = t.tgrelid and a.attname = 'organization_id'
                 where t.tgrelid = ?::regclass
                   and not t.tgisinternal
                   and t.tgenabled <> 'D'
                   and t.tgfoid = 'platform.prevent_organization_id_change()'::regprocedure
                   and (t.tgtype & 1) = 1   -- FOR EACH ROW
                   and (t.tgtype & 2) = 2   -- BEFORE
                   and (t.tgtype & 16) = 16 -- UPDATE
                   and a.attnum = any (t.tgattr::int2[])
                SQL, [$table->qualified]);

            $this->assertSame(1, (int) $trigger->total, "{$table->qualified} needs the BEFORE UPDATE OF organization_id immutability trigger");
        }
    }

    /**
     * @return list<object{qualified: string, schema: string, name: string, rls: bool, forced: bool}>
     */
    private function tenantTables(): array
    {
        return DB::select(<<<'SQL'
            select format('%I.%I', n.nspname, c.relname) as qualified, n.nspname as schema, c.relname as name,
                   c.relrowsecurity as rls, c.relforcerowsecurity as forced
              from pg_class c join pg_namespace n on n.oid = c.relnamespace
             where c.relkind in ('r', 'p')
               and n.nspname not in ('pg_catalog', 'information_schema', 'pg_toast')
               and exists (select 1 from pg_attribute a
                            where a.attrelid = c.oid and a.attname = 'organization_id' and not a.attisdropped)
             order by 1
            SQL);
    }

    public function test_tables_without_organization_id_are_on_the_global_allowlist(): void
    {
        $globals = array_column(DB::select(<<<'SQL'
            select format('%I.%I', n.nspname, c.relname) as qualified
              from pg_class c join pg_namespace n on n.oid = c.relnamespace
             where c.relkind in ('r', 'p')
               and n.nspname not in ('pg_catalog', 'information_schema', 'pg_toast')
               and not exists (select 1 from pg_attribute a
                                where a.attrelid = c.oid and a.attname = 'organization_id' and not a.attisdropped)
             order by 1
            SQL), 'qualified');

        $this->assertEqualsCanonicalizing(self::GLOBAL_TABLES, $globals);
    }

    public function test_application_schemas_are_owned_by_the_migrator(): void
    {
        $foreign = DB::select(<<<'SQL'
            select format('%I.%I', n.nspname, c.relname) as qualified, pg_get_userbyid(c.relowner) as owner
              from pg_class c join pg_namespace n on n.oid = c.relnamespace
             where c.relkind in ('r', 'p', 'S', 'v', 'm')
               and n.nspname not in ('pg_catalog', 'information_schema', 'pg_toast')
               and pg_get_userbyid(c.relowner) <> ?
            SQL, [PostgresRoles::migrator()]);

        $this->assertSame([], $foreign);
    }

    public function test_only_reviewed_security_definer_functions_exist(): void
    {
        $definers = DB::select(<<<'SQL'
            select p.oid::regprocedure::text as signature, pg_get_userbyid(p.proowner) as owner,
                   p.proconfig::text as config, has_function_privilege('public', p.oid, 'EXECUTE') as public_execute
              from pg_proc p join pg_namespace n on n.oid = p.pronamespace
             where p.prosecdef
               and n.nspname not in ('pg_catalog', 'information_schema')
             order by 1
            SQL);

        $this->assertEqualsCanonicalizing(
            array_keys(self::REVIEWED_SECURITY_DEFINER_FUNCTIONS),
            array_column($definers, 'signature'),
            'Unreviewed SECURITY DEFINER function present (or a reviewed one is missing).'
        );

        foreach ($definers as $function) {
            [$owner, $config] = self::REVIEWED_SECURITY_DEFINER_FUNCTIONS[$function->signature];
            $this->assertSame($owner, $function->owner, "{$function->signature} owner");
            $this->assertSame($config, $function->config, "{$function->signature} must pin search_path");
            $this->assertFalse($function->public_execute, "{$function->signature} must not be executable by PUBLIC");
        }
    }

    public function test_views_use_security_invoker(): void
    {
        $views = DB::select(<<<'SQL'
            select format('%I.%I', n.nspname, c.relname) as qualified
              from pg_class c join pg_namespace n on n.oid = c.relnamespace
             where c.relkind = 'v'
               and n.nspname not in ('pg_catalog', 'information_schema')
               and not coalesce('security_invoker=true' = any (c.reloptions), false)
            SQL);

        $this->assertSame([], $views);
    }
}
