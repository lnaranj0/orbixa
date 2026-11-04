<?php

namespace Modules\Platform\Tests\Security\Support;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Str;
use Modules\Platform\Contracts\Tenancy\MembershipVerifier;
use Modules\Platform\Contracts\Tenancy\OrganizationId;
use Modules\Platform\Contracts\Tenancy\TenantContext;

/**
 * Two fresh organizations and one actor that is a member of both.
 */
trait TenantFixtures
{
    protected OrganizationId $orgA;

    protected OrganizationId $orgB;

    protected GenericUser $actor;

    protected TenantContext $tenant;

    protected function setUpTenants(): void
    {
        $this->orgA = OrganizationId::fromString((string) Str::uuid());
        $this->orgB = OrganizationId::fromString((string) Str::uuid());
        $this->actor = new GenericUser(['id' => random_int(1, PHP_INT_MAX)]);

        $this->app->instance(MembershipVerifier::class, (new AllowListMembershipVerifier)
            ->allow($this->actor, $this->orgA)
            ->allow($this->actor, $this->orgB));

        $this->tenant = $this->app->make(TenantContext::class);
    }
}
