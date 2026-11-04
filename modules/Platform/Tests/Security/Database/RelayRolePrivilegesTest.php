<?php

namespace Modules\Platform\Tests\Security\Database;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Platform\Contracts\Events\ActorReference;
use Modules\Platform\Contracts\Events\DomainEvent;
use Modules\Platform\Contracts\Events\DomainEventRecorder;
use Modules\Platform\Contracts\Tenancy\OrganizationId;
use Modules\Platform\Infrastructure\Database\PostgresRoles;
use Modules\Platform\Tests\Security\SecurityTestCase;
use Modules\Platform\Tests\Security\Support\TenantFixtures;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Option A relay: cross-organization access limited to PENDING rows of
 * events.domain_outbox and to the publication columns. Nothing else.
 */
final class RelayRolePrivilegesTest extends SecurityTestCase
{
    use TenantFixtures;

    private const RELAY = 'pgsql_relay';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenants();
    }

    public function test_relay_role_has_no_elevated_attributes_or_memberships(): void
    {
        $role = DB::connection(self::RELAY)->selectOne(
            "select current_user as name, rolsuper, rolbypassrls, rolcreaterole, rolcreatedb,
                    pg_has_role(current_user, ?, 'MEMBER') as migrator,
                    pg_has_role(current_user, ?, 'MEMBER') as runtime
               from pg_roles where rolname = current_user",
            [PostgresRoles::migrator(), PostgresRoles::runtime()]
        );

        $this->assertSame(PostgresRoles::relay(), $role->name);
        $this->assertFalse($role->rolsuper);
        $this->assertFalse($role->rolbypassrls);
        $this->assertFalse($role->rolcreaterole);
        $this->assertFalse($role->rolcreatedb);
        $this->assertFalse($role->migrator);
        $this->assertFalse($role->runtime);
    }

    public function test_relay_reads_events_of_every_organization(): void
    {
        $idA = $this->record($this->orgA);
        $idB = $this->record($this->orgB);

        $visible = array_column(DB::connection(self::RELAY)->select(
            'select event_id::text as id from events.domain_outbox where event_id in (?, ?)',
            [$idA, $idB]
        ), 'id');

        $this->assertEqualsCanonicalizing([$idA, $idB], $visible);
    }

    public function test_relay_marks_pending_events_once_and_cannot_touch_published_ones(): void
    {
        $id = $this->record($this->orgA);
        $relay = DB::connection(self::RELAY);

        $this->assertSame(1, $relay->update('update events.domain_outbox set published_at = now() where event_id = ?', [$id]));
        $this->assertSame(0, $relay->update('update events.domain_outbox set published_at = null, attempts = 99 where event_id = ?', [$id]));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function forbiddenStatements(): array
    {
        return [
            'read tenant data' => ['select * from platform.tenant_probe'],
            'read idempotency keys' => ['select * from platform.idempotency_keys'],
            'read consumer receipts' => ['select * from events.consumer_receipts'],
            'read users' => ['select * from public.users'],
            'read migration history' => ['select * from public.migrations'],
            'insert events' => ["insert into events.domain_outbox (organization_id, aggregate_type, aggregate_id, event_name, event_version, actor_type, correlation_id) values (gen_random_uuid(), 'x', gen_random_uuid(), 'platform.probe.forged', 1, 'system', gen_random_uuid())"],
            'delete events' => ['delete from events.domain_outbox'],
            'rewrite payload' => ["update events.domain_outbox set payload = '{}'::jsonb"],
            'move organization' => ['update events.domain_outbox set organization_id = gen_random_uuid()'],
            'truncate outbox' => ['truncate events.domain_outbox'],
            'disable RLS' => ['alter table events.domain_outbox disable row level security'],
            'create schema' => ['create schema relay_escalation'],
            'create temp table' => ['create temp table relay_escalation (id int)'],
        ];
    }

    #[DataProvider('forbiddenStatements')]
    public function test_relay_cannot_execute_statement(string $sql): void
    {
        $this->assertSqlState('42501', fn (Connection $connection) => $connection->statement($sql), self::RELAY);
    }

    private function record(OrganizationId $organization): string
    {
        return $this->tenant->runFor($this->actor, $organization, fn () => $this->app->make(DomainEventRecorder::class)->record(
            new DomainEvent('platform.relay_probe.created', 1, 'relay_probe', (string) Str::uuid()),
            ActorReference::system(),
        ));
    }
}
