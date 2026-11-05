<?php

namespace Modules\GitHubIntegration\Tests\Security;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\GitHubIntegration\Infrastructure\InstallationRouter;
use Modules\IdentityTenancy\Application\CreateOrganization;
use Modules\IdentityTenancy\Infrastructure\Persistence\User;
use Modules\Platform\Contracts\Tenancy\IntegrationPrincipal;
use Modules\Platform\Contracts\Tenancy\OrganizationId;
use Modules\Platform\Contracts\Tenancy\TenantAccessDenied;
use Modules\Platform\Contracts\Tenancy\TenantContext;
use Modules\Platform\Contracts\Tenancy\TenantContextViolation;
use Modules\Platform\Tests\Security\SecurityTestCase;

/**
 * 06 increment 3 (G-A, G-B): routing a webhook installation to its
 * organization without a tenant context, and opening a tenant context for a
 * non-human integration principal.
 */
final class InstallationRoutingTest extends SecurityTestCase
{
    private TenantContext $tenant;

    private User $owner;

    private OrganizationId $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->app->make(TenantContext::class);
        $this->owner = User::factory()->create(['email' => Str::uuid().'@example.test']);
        $this->organization = $this->app->make(CreateOrganization::class)->handle($this->owner, 'Routing Inc');
    }

    public function test_router_returns_only_the_organization_of_an_active_installation(): void
    {
        $external = $this->installation('active');
        $router = $this->app->make(InstallationRouter::class);

        // Outside any tenant context the table itself shows nothing...
        $this->assertSame(0, (int) DB::selectOne('select count(*) as total from integrations.github_installations')->total);
        // ...but the reviewed function routes it.
        $this->assertTrue($router->organizationFor($external)?->equals($this->organization));
        $this->assertNull($router->organizationFor(random_int(10_000_000, 99_999_999)));
        $this->assertNull($router->organizationFor(-1));

        $this->tenant->runFor($this->owner, $this->organization, fn () => DB::update(
            "update integrations.github_installations set status = 'suspended' where installation_external_id = ?",
            [$external]
        ));
        $this->assertNull($router->organizationFor($external), 'Suspended installations are not routed.');
    }

    public function test_integration_context_is_verified_inside_the_transaction(): void
    {
        $external = $this->installation('active');
        $principal = IntegrationPrincipal::github($external);

        $seen = $this->tenant->runForIntegration($principal, $this->organization, function () {
            $this->assertTrue($this->tenant->current()?->equals($this->organization));

            return (int) DB::selectOne('select count(*) as total from integrations.github_installations')->total;
        });
        $this->assertSame(1, $seen);
        $this->assertNull($this->tenant->current(), 'The context ends with the transaction.');

        // Another organization, a foreign principal or a revoked installation: denied.
        $stranger = User::factory()->create(['email' => Str::uuid().'@example.test']);
        $other = $this->app->make(CreateOrganization::class)->handle($stranger, 'Other Inc');
        $this->assertDenied(fn () => $this->tenant->runForIntegration($principal, $other, fn () => null));
        $this->assertDenied(fn () => $this->tenant->runForIntegration(IntegrationPrincipal::github(random_int(10_000_000, 99_999_999)), $this->organization, fn () => null));

        $this->tenant->runFor($this->owner, $this->organization, fn () => DB::update(
            "update integrations.github_installations set status = 'revoked' where installation_external_id = ?",
            [$external]
        ));
        $this->assertDenied(fn () => $this->tenant->runForIntegration($principal, $this->organization, fn () => null));

        // Never nested inside a human context.
        $this->expectException(TenantContextViolation::class);
        $this->tenant->runFor($this->owner, $this->organization, fn () => $this->tenant->runForIntegration($principal, $this->organization, fn () => null));
    }

    private function installation(string $status): int
    {
        $external = random_int(10_000_000, 99_999_999);
        $this->tenant->runFor($this->owner, $this->organization, fn () => DB::insert(
            "insert into integrations.github_installations (organization_id, installation_external_id, account_login, account_type, status, linked_by_membership_id)
             values (platform.current_organization_id(), ?, 'acme', 'Organization', ?, (select id from identity.organization_memberships limit 1))",
            [$external, $status]
        ));

        return $external;
    }

    private function assertDenied(\Closure $operation): void
    {
        try {
            $operation();
            $this->fail('Expected TenantAccessDenied.');
        } catch (TenantAccessDenied) {
            $this->assertNull($this->tenant->current());
        }
    }
}
