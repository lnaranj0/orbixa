<?php

namespace Modules\IdentityTenancy\Contracts;

use Modules\Platform\Contracts\Tenancy\OrganizationId;

final readonly class OrganizationSummary
{
    public function __construct(public OrganizationId $id, public string $name) {}
}
