<?php

namespace Modules\Platform\Contracts;

use Modules\Platform\Contracts\Modules\ModuleManifest;

final class PlatformManifest implements ModuleManifest
{
    public function module(): string
    {
        return 'Platform';
    }

    public function ownedTables(): array
    {
        return [
            // Framework infrastructure tables.
            'public.migrations',
            'public.cache',
            'public.cache_locks',
            'public.jobs',
            'public.job_batches',
            'public.failed_jobs',
            'public.sessions',
            // Provisional until 01-identity-tenancy creates the IdentityTenancy
            // module, which takes ownership (and converts users.id to uuid).
            'public.users',
            'public.password_reset_tokens',
            // Tenancy foundation, outbox and idempotency.
            'platform.tenant_probe',
            'platform.idempotency_keys',
            'events.domain_outbox',
            'events.consumer_receipts',
        ];
    }
}
