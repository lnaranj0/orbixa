<?php

namespace Modules\IdentityTenancy\Tests\Security;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\IdentityTenancy\Infrastructure\Persistence\User;
use Modules\Platform\Tests\Security\SecurityTestCase;

/**
 * D2: global identity tables owned by IdentityTenancy, uuid ids, and the
 * individually reviewed runtime privileges.
 */
final class IdentityUsersTest extends SecurityTestCase
{
    private const TABLE_PRIVILEGES = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'TRUNCATE', 'REFERENCES', 'TRIGGER'];

    public function test_users_use_uuid_ids_without_a_sequence(): void
    {
        $id = DB::selectOne(
            "select data_type, column_default from information_schema.columns
              where table_schema = 'identity' and table_name = 'users' and column_name = 'id'"
        );
        $this->assertSame('uuid', $id->data_type);
        $this->assertStringContainsString('uuidv7()', (string) $id->column_default);

        $this->assertSame(0, (int) DB::selectOne(
            "select count(*) as total from pg_class c join pg_namespace n on n.oid = c.relnamespace
              where c.relkind = 'S' and c.relname = 'users_id_seq'"
        )->total);

        $this->assertSame('uuid', DB::selectOne(
            "select data_type from information_schema.columns
              where table_schema = 'public' and table_name = 'sessions' and column_name = 'user_id'"
        )->data_type);
    }

    public function test_identity_tables_no_longer_exist_in_public(): void
    {
        $this->assertSame([], DB::select(
            "select tablename from pg_tables where schemaname = 'public' and tablename in ('users', 'password_reset_tokens')"
        ));
    }

    public function test_users_table_privileges_are_reviewed(): void
    {
        $this->assertTablePrivileges('identity.users', ['SELECT', 'INSERT']);

        $updatable = ['name', 'email', 'email_verified_at', 'password', 'remember_token', 'updated_at'];
        $columns = DB::select(
            "select column_name, has_column_privilege(current_user, 'identity.users', column_name, 'UPDATE') as granted
               from information_schema.columns where table_schema = 'identity' and table_name = 'users'"
        );
        $this->assertNotEmpty($columns);
        foreach ($columns as $column) {
            $this->assertSame(in_array($column->column_name, $updatable, true), $column->granted, "UPDATE on identity.users.{$column->column_name}");
        }
    }

    public function test_password_reset_tokens_privileges_are_reviewed(): void
    {
        $this->assertTablePrivileges('identity.password_reset_tokens', ['SELECT', 'INSERT', 'DELETE']);
    }

    public function test_runtime_creates_users_with_uuid_v7_and_cannot_delete_them(): void
    {
        $user = User::factory()->create(['email' => Str::uuid().'@example.test']);

        $this->assertTrue(Str::isUuid($user->getKey()));
        $this->assertSame('7', $user->getKey()[14]);
        $this->assertSame($user->getKey(), DB::selectOne('select id::text as id from identity.users where id = ?', [$user->getKey()])->id);

        $this->assertSqlState('42501', fn (Connection $connection) => $connection->delete('delete from identity.users where id = ?', [$user->getKey()]));
        $this->assertSqlState('42501', fn (Connection $connection) => $connection->update('update identity.users set id = gen_random_uuid() where id = ?', [$user->getKey()]));
    }

    public function test_email_is_unique(): void
    {
        $email = Str::uuid().'@example.test';
        User::factory()->create(['email' => $email]);

        $this->assertSqlState('23505', fn (Connection $connection) => $connection->insert(
            "insert into identity.users (name, email, password) values ('dup', ?, 'x')",
            [$email]
        ));
    }

    /**
     * @param  list<string>  $expected
     */
    private function assertTablePrivileges(string $table, array $expected): void
    {
        foreach (self::TABLE_PRIVILEGES as $privilege) {
            $this->assertSame(
                in_array($privilege, $expected, true),
                DB::selectOne('select has_table_privilege(current_user, ?, ?) as granted', [$table, $privilege])->granted,
                "Unexpected {$privilege} on {$table}"
            );
        }
    }
}
