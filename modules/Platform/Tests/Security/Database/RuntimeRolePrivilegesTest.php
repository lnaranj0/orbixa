<?php

namespace Modules\Platform\Tests\Security\Database;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Modules\Platform\Infrastructure\Database\PostgresRoles;
use Modules\Platform\Tests\Security\SecurityTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Spec "Rol sin bypass": the runtime role cannot own, alter or bypass RLS.
 */
final class RuntimeRolePrivilegesTest extends SecurityTestCase
{
    public function test_runtime_role_has_no_elevated_attributes(): void
    {
        $role = DB::selectOne(
            'select rolsuper, rolbypassrls, rolcreaterole, rolcreatedb, rolreplication
               from pg_roles where rolname = current_user'
        );

        $this->assertFalse($role->rolsuper);
        $this->assertFalse($role->rolbypassrls);
        $this->assertFalse($role->rolcreaterole);
        $this->assertFalse($role->rolcreatedb);
        $this->assertFalse($role->rolreplication);
    }

    public function test_runtime_role_is_not_a_member_of_the_migrator(): void
    {
        $membership = DB::selectOne(
            "select pg_has_role(current_user, ?, 'MEMBER') as member",
            [PostgresRoles::migrator()]
        );

        $this->assertFalse($membership->member);
    }

    public function test_runtime_role_owns_no_objects(): void
    {
        $owned = DB::selectOne(
            "select count(*) as total from pg_class c join pg_namespace n on n.oid = c.relnamespace
              where c.relowner = (select oid from pg_roles where rolname = current_user)
                and n.nspname not in ('pg_catalog', 'information_schema', 'pg_toast')"
        );

        $this->assertSame(0, (int) $owned->total);
    }

    public function test_runtime_role_has_no_tenant_default_setting(): void
    {
        $settings = DB::select(
            'select unnest(setconfig) as setting from pg_db_role_setting
              where setrole = (select oid from pg_roles where rolname = current_user)'
        );

        foreach ($settings as $setting) {
            $this->assertStringStartsNotWith('app.', $setting->setting);
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function forbiddenStatements(): array
    {
        return [
            'disable RLS' => ['42501', 'alter table platform.tenant_probe disable row level security'],
            'unforce RLS' => ['42501', 'alter table platform.tenant_probe no force row level security'],
            'drop policy' => ['42501', 'drop policy tenant_isolation on platform.tenant_probe'],
            'alter policy' => ['42501', 'alter policy tenant_isolation on platform.tenant_probe using (true)'],
            'alter table' => ['42501', 'alter table platform.tenant_probe add column escalation int'],
            'truncate bypasses RLS' => ['42501', 'truncate platform.tenant_probe'],
            'create in public' => ['42501', 'create table public.escalation_probe (id int)'],
            'create in platform' => ['42501', 'create table platform.escalation_probe (id int)'],
            'create temp table' => ['42501', 'create temp table escalation_probe (id int)'],
            'create schema' => ['42501', 'create schema escalation_probe'],
            'read migration history' => ['42501', 'select * from public.migrations'],
            'alter tenant function' => ['42501', 'alter function platform.current_organization_id() security definer'],
            'drop immutability trigger' => ['42501', 'drop trigger tenant_probe_organization_id_immutable on platform.tenant_probe'],
        ];
    }

    #[DataProvider('forbiddenStatements')]
    public function test_runtime_role_cannot_execute_privileged_statement(string $sqlState, string $sql): void
    {
        $this->assertSqlState($sqlState, fn (Connection $connection) => $connection->statement($sql));
    }

    public function test_runtime_role_cannot_assume_the_migrator_role(): void
    {
        $migrator = PostgresRoles::migrator();

        $this->assertSqlState('42501', fn (Connection $connection) => $connection->statement("set role {$migrator}"));
    }
}
