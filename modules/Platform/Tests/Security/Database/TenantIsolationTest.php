<?php

namespace Modules\Platform\Tests\Security\Database;

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Platform\Contracts\Tenancy\MembershipVerifier;
use Modules\Platform\Contracts\Tenancy\OrganizationId;
use Modules\Platform\Contracts\Tenancy\TenantAccessDenied;
use Modules\Platform\Contracts\Tenancy\TenantContext;
use Modules\Platform\Contracts\Tenancy\TenantContextViolation;
use Modules\Platform\Tests\Security\SecurityTestCase;
use Modules\Platform\Tests\Security\Support\AllowListMembershipVerifier;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Spec "RLS obligatorio y contexto seguro" on platform.tenant_probe, with the
 * tenant context established only through TenantContext + membership check.
 */
final class TenantIsolationTest extends SecurityTestCase
{
    private OrganizationId $orgA;

    private OrganizationId $orgB;

    private GenericUser $actor;

    private TenantContext $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = OrganizationId::fromString((string) Str::uuid());
        $this->orgB = OrganizationId::fromString((string) Str::uuid());
        $this->actor = new GenericUser(['id' => random_int(1, PHP_INT_MAX)]);

        $this->app->instance(MembershipVerifier::class, (new AllowListMembershipVerifier)
            ->allow($this->actor, $this->orgA)
            ->allow($this->actor, $this->orgB));

        $this->tenant = $this->app->make(TenantContext::class);
    }

    public function test_without_context_nothing_is_visible_and_writes_are_rejected(): void
    {
        $id = $this->insertProbe($this->orgA, 'a');

        $this->assertSame(0, $this->countProbe($id));
        $this->assertSqlState('42501', fn (Connection $connection) => $connection->insert(
            'insert into platform.tenant_probe (organization_id, label) values (?, ?)',
            [$this->orgA->value, 'no-context']
        ));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidContextValues(): array
    {
        return [
            'empty' => [''],
            'not a uuid' => ['abc'],
            'sql fragment' => ["' or true --"],
            'almost uuid' => ['00000000-0000-0000-0000-00000000000z'],
        ];
    }

    #[DataProvider('invalidContextValues')]
    public function test_empty_or_malformed_context_fails_closed_without_errors(string $value): void
    {
        $id = $this->insertProbe($this->orgA, 'a');

        $visible = DB::transaction(function () use ($value, $id) {
            // Direct GUC manipulation simulates a compromised or buggy caller.
            DB::selectOne("select set_config('app.organization_id', ?, true)", [$value]);

            return $this->countProbe($id);
        });

        $this->assertSame(0, $visible);
    }

    public function test_organization_only_sees_its_own_rows(): void
    {
        $idA = $this->insertProbe($this->orgA, 'a');
        $idB = $this->insertProbe($this->orgB, 'b');

        $this->tenant->runFor($this->actor, $this->orgA, function () use ($idA, $idB) {
            $this->assertSame(1, $this->countProbe($idA));
            $this->assertSame(0, $this->countProbe($idB));
            $this->assertSame(
                [],
                DB::select('select id from platform.tenant_probe where organization_id = ?', [$this->orgB->value])
            );
        });
    }

    public function test_cannot_update_or_delete_rows_of_another_organization(): void
    {
        $idB = $this->insertProbe($this->orgB, 'b');

        $this->tenant->runFor($this->actor, $this->orgA, function () use ($idB) {
            $this->assertSame(0, DB::update("update platform.tenant_probe set label = 'hijacked' where id = ?", [$idB]));
            $this->assertSame(0, DB::delete('delete from platform.tenant_probe where id = ?', [$idB]));
        });

        $this->tenant->runFor($this->actor, $this->orgB, function () use ($idB) {
            $this->assertSame('b', DB::selectOne('select label from platform.tenant_probe where id = ?', [$idB])->label);
        });
    }

    public function test_cannot_insert_rows_for_another_organization(): void
    {
        $this->tenant->runFor($this->actor, $this->orgA, function () {
            $this->assertSqlState('42501', fn (Connection $connection) => $connection->insert(
                'insert into platform.tenant_probe (organization_id, label) values (?, ?)',
                [$this->orgB->value, 'cross-tenant']
            ));
        });
    }

    public function test_organization_id_is_immutable(): void
    {
        $idA = $this->insertProbe($this->orgA, 'a');

        $this->tenant->runFor($this->actor, $this->orgA, function () use ($idA) {
            $this->assertSqlState('42501', fn (Connection $connection) => $connection->update(
                'update platform.tenant_probe set organization_id = ? where id = ?',
                [$this->orgB->value, $idA]
            ));
        });
    }

    public function test_context_does_not_survive_commit_on_reused_connection(): void
    {
        $pdo = DB::connection()->getPdo();
        $idA = $this->insertProbe($this->orgA, 'a');

        $this->assertSame($pdo, DB::connection()->getPdo(), 'Expected the same physical connection.');
        $this->assertContains(DB::selectOne("select current_setting('app.organization_id', true) as value")->value, ['', null]);
        $this->assertNull(DB::selectOne('select platform.current_organization_id() as id')->id);
        $this->assertSame(0, $this->countProbe($idA));
    }

    public function test_context_is_cleared_when_nested_in_an_outer_transaction(): void
    {
        $idA = $this->insertProbe($this->orgA, 'a');

        DB::transaction(function () use ($idA) {
            $this->tenant->runFor($this->actor, $this->orgA, fn () => $this->assertSame(1, $this->countProbe($idA)));

            // The savepoint was released, not rolled back: context must still be gone.
            $this->assertNull(DB::selectOne('select platform.current_organization_id() as id')->id);
            $this->assertSame(0, $this->countProbe($idA));
        });
    }

    public function test_savepoint_rollback_restores_the_outer_context(): void
    {
        $this->tenant->runFor($this->actor, $this->orgA, function () {
            DB::beginTransaction();
            DB::selectOne("select set_config('app.organization_id', ?, true)", [$this->orgB->value]);
            DB::rollBack();

            $this->assertSame(
                $this->orgA->value,
                DB::selectOne('select platform.current_organization_id()::text as id')->id
            );
        });
    }

    public function test_context_requires_an_authorized_membership(): void
    {
        $outsider = OrganizationId::fromString((string) Str::uuid());

        try {
            $this->tenant->runFor($this->actor, $outsider, fn () => $this->fail('Callback must not run.'));
            $this->fail('Expected TenantAccessDenied.');
        } catch (TenantAccessDenied) {
            $this->assertNull($this->tenant->current());
            $this->assertNull(DB::selectOne('select platform.current_organization_id() as id')->id);
        }
    }

    public function test_switching_organization_inside_an_active_context_is_rejected(): void
    {
        $this->expectException(TenantContextViolation::class);

        $this->tenant->runFor($this->actor, $this->orgA, function () {
            $this->tenant->runFor($this->actor, $this->orgB, fn () => null);
        });
    }

    public function test_reentering_an_active_context_with_another_actor_is_rejected(): void
    {
        $otherActor = new GenericUser(['id' => random_int(1, PHP_INT_MAX)]);
        $this->app->make(MembershipVerifier::class)->allow($otherActor, $this->orgA);

        $this->expectException(TenantContextViolation::class);

        $this->tenant->runFor($this->actor, $this->orgA, function () use ($otherActor) {
            $this->tenant->runFor($otherActor, $this->orgA, fn () => null);
        });
    }

    private function insertProbe(OrganizationId $organization, string $label): string
    {
        return $this->tenant->runFor($this->actor, $organization, fn () => DB::selectOne(
            'insert into platform.tenant_probe (organization_id, label)
             values (platform.current_organization_id(), ?) returning id',
            [$label]
        )->id);
    }

    private function countProbe(string $id): int
    {
        return (int) DB::selectOne('select count(*) as total from platform.tenant_probe where id = ?', [$id])->total;
    }
}
