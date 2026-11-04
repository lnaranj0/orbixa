<?php

namespace Modules\Platform\Contracts\Events;

use Modules\Platform\Contracts\Tenancy\TenantContextRequired;

/**
 * Records a domain event in the transactional outbox, in the same database
 * transaction as the business mutation (spec "Outbox transaccional").
 */
interface DomainEventRecorder
{
    /**
     * @return string the generated event id
     *
     * @throws TenantContextRequired outside TenantContext::runFor()
     */
    public function record(DomainEvent $event, ActorReference $actor): string;
}
