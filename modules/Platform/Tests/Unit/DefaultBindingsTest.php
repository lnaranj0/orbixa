<?php

namespace Modules\Platform\Tests\Unit;

use Modules\Platform\Contracts\Events\DomainEventRecorder;
use Modules\Platform\Contracts\Idempotency\IdempotencyStore;
use Modules\Platform\Contracts\Tenancy\AdmissionVerifier;
use Modules\Platform\Contracts\Tenancy\MembershipVerifier;
use Modules\Platform\Contracts\Tenancy\TenantContext;
use Tests\TestCase;

/**
 * Resolves the real production bindings (security tests replace the
 * membership verifier with a test double, so they never load the default).
 */
final class DefaultBindingsTest extends TestCase
{
    public function test_contracts_resolve_to_their_implementations(): void
    {
        // IdentityTenancy replaces the deny-all membership verifier; Platform only requires one.
        $this->assertInstanceOf(MembershipVerifier::class, $this->app->make(MembershipVerifier::class));
        // IdentityTenancy replaces the deny-all admission verifier; Platform only requires one.
        $this->assertInstanceOf(AdmissionVerifier::class, $this->app->make(AdmissionVerifier::class));
        $this->assertInstanceOf(TenantContext::class, $this->app->make(TenantContext::class));
        $this->assertInstanceOf(DomainEventRecorder::class, $this->app->make(DomainEventRecorder::class));
        $this->assertInstanceOf(IdempotencyStore::class, $this->app->make(IdempotencyStore::class));
    }
}
