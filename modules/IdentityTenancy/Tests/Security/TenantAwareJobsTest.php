<?php

namespace Modules\IdentityTenancy\Tests\Security;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\IdentityTenancy\Application\AcceptInvitation;
use Modules\IdentityTenancy\Application\CreateOrganization;
use Modules\IdentityTenancy\Application\InviteMember;
use Modules\IdentityTenancy\Application\RevokeMembership;
use Modules\IdentityTenancy\Contracts\TenantAwareJob;
use Modules\IdentityTenancy\Contracts\TenantScope;
use Modules\IdentityTenancy\Infrastructure\Persistence\User;
use Modules\Platform\Contracts\Tenancy\OrganizationId;
use Modules\Platform\Contracts\Tenancy\TenantAccessDenied;
use Modules\Platform\Contracts\Tenancy\TenantContext;
use Modules\Platform\Tests\Security\SecurityTestCase;
use Throwable;

/**
 * Spec "Job encolado antes de la revocación" and "probar pools y trabajos
 * asíncronos" (task 4), through the real queue pipeline (sync driver runs
 * CallQueuedHandler, middleware and bus pipes).
 */
final class TenantAwareJobsTest extends SecurityTestCase
{
    private TenantContext $tenant;

    private User $owner;

    private OrganizationId $organization;

    protected function setUp(): void
    {
        parent::setUp();
        ProbeTenantJob::$failure = null;
        ProbeTenantJob::$observedOrganization = null;
        $this->tenant = $this->app->make(TenantContext::class);
        $this->owner = $this->user();
        $this->organization = $this->app->make(CreateOrganization::class)->handle($this->owner, 'Jobs Inc');
    }

    public function test_job_runs_inside_the_tenant_context_of_its_organization(): void
    {
        $label = 'job-'.Str::uuid();

        dispatch(new ProbeTenantJob($this->organization, $this->owner, $label));

        $this->assertSame($this->organization->value, ProbeTenantJob::$observedOrganization);
        $this->assertNull(ProbeTenantJob::$failure);
        $this->assertSame(1, $this->tenant->runFor($this->owner, $this->organization, fn () => (int) DB::selectOne('select count(*) as total from platform.tenant_probe where label = ?', [$label])->total));
    }

    public function test_job_enqueued_before_revocation_fails_closed_without_side_effects(): void
    {
        $member = $this->member('developer');
        $label = 'revoked-'.Str::uuid();
        $job = new ProbeTenantJob($this->organization, $member, $label); // enqueued while still a member

        $membershipId = $this->tenant->runFor($this->owner, $this->organization, fn () => (string) DB::selectOne('select id::text as id from identity.organization_memberships where user_id = ?', [$member->getKey()])->id);
        $this->tenant->runFor($this->owner, $this->organization, fn () => $this->app->make(RevokeMembership::class)->handle($this->owner, $membershipId));

        dispatch($job); // executes after the revocation

        $this->assertInstanceOf(TenantAccessDenied::class, ProbeTenantJob::$failure, 'The job must be failed, not retried or run.');
        $this->assertNull(ProbeTenantJob::$observedOrganization, 'The handler must not run.');
        $this->assertSame(0, $this->tenant->runFor($this->owner, $this->organization, fn () => (int) DB::selectOne('select count(*) as total from platform.tenant_probe where label = ?', [$label])->total));
    }

    public function test_tampered_or_unknown_actor_fails_closed(): void
    {
        $outsider = $this->user();

        dispatch(new ProbeTenantJob($this->organization, $outsider, 'outsider'));
        $this->assertInstanceOf(TenantAccessDenied::class, ProbeTenantJob::$failure);

        ProbeTenantJob::$failure = null;
        $ghost = new User(['email' => 'ghost@example.test']);
        $ghost->setAttribute('id', (string) Str::uuid7());
        dispatch(new ProbeTenantJob($this->organization, $ghost, 'ghost'));
        $this->assertInstanceOf(TenantAccessDenied::class, ProbeTenantJob::$failure);
    }

    public function test_payload_carries_ids_only(): void
    {
        $payload = serialize(new ProbeTenantJob($this->organization, $this->owner, 'payload'));

        $this->assertStringContainsString($this->organization->value, $payload);
        $this->assertStringContainsString((string) $this->owner->getKey(), $payload);
        $this->assertStringNotContainsString((string) $this->owner->email, $payload);
        $this->assertStringNotContainsString('password', $payload);
    }

    public function test_context_does_not_leak_into_the_next_job_on_the_same_connection(): void
    {
        dispatch(new ProbeTenantJob($this->organization, $this->owner, 'first-'.Str::uuid()));

        $this->assertNull(DB::selectOne('select platform.current_organization_id() as id')->id);
        $this->assertSame(0, (int) DB::selectOne('select count(*) as total from platform.tenant_probe')->total);
    }

    private function user(): User
    {
        return User::factory()->create(['email' => Str::uuid().'@example.test']);
    }

    private function member(string $role): User
    {
        $user = $this->user();
        $ticket = $this->tenant->runFor($this->owner, $this->organization, fn () => $this->app->make(InviteMember::class)->handle($this->owner, $user->email, $role))->ticket;
        $this->app->make(AcceptInvitation::class)->handle($user, $this->organization, $ticket->reveal());

        return $user;
    }
}

/**
 * Test job: records the organization it observed and writes a probe row.
 */
final class ProbeTenantJob implements ShouldQueue, TenantAwareJob
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public static ?string $observedOrganization = null;

    public static ?Throwable $failure = null;

    private readonly TenantScope $scope;

    public function __construct(OrganizationId $organization, Authenticatable $actor, private readonly string $label)
    {
        $this->scope = TenantScope::of($organization, $actor);
    }

    public function tenantScope(): TenantScope
    {
        return $this->scope;
    }

    public function handle(): void
    {
        self::$observedOrganization = (string) DB::selectOne('select platform.current_organization_id()::text as id')->id;
        DB::insert('insert into platform.tenant_probe (organization_id, label) values (platform.current_organization_id(), ?)', [$this->label]);
    }

    public function failed(?Throwable $exception): void
    {
        self::$failure = $exception;
    }
}
