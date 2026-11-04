<?php

namespace Modules\Platform\Tests\Unit;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Str;
use Modules\Platform\Contracts\Events\DomainEventRecorder;
use Modules\Platform\Contracts\Idempotency\IdempotencyStore;
use Modules\Platform\Contracts\Tenancy\MembershipVerifier;
use Modules\Platform\Contracts\Tenancy\OrganizationId;
use Modules\Platform\Contracts\Tenancy\TenantAccessDenied;
use Modules\Platform\Contracts\Tenancy\TenantContext;
use Modules\Platform\Infrastructure\Tenancy\DenyAllMembershipVerifier;
use Tests\TestCase;

/**
 * Resolves the real production bindings (security tests replace the
 * membership verifier with a test double, so they never load the default).
 */
final class DefaultBindingsTest extends TestCase
{
    public function test_contracts_resolve_to_their_implementations(): void
    {
        $this->assertInstanceOf(DenyAllMembershipVerifier::class, $this->app->make(MembershipVerifier::class));
        $this->assertInstanceOf(TenantContext::class, $this->app->make(TenantContext::class));
        $this->assertInstanceOf(DomainEventRecorder::class, $this->app->make(DomainEventRecorder::class));
        $this->assertInstanceOf(IdempotencyStore::class, $this->app->make(IdempotencyStore::class));
    }

    public function test_default_membership_verifier_denies_everyone_before_touching_the_database(): void
    {
        $this->expectException(TenantAccessDenied::class);

        $this->app->make(TenantContext::class)->runFor(
            new GenericUser(['id' => 1]),
            OrganizationId::fromString((string) Str::uuid()),
            fn () => $this->fail('Callback must not run without an authorized membership.'),
        );
    }
}
