<?php

namespace Modules\IdentityTenancy\Contracts;

use DateTimeImmutable;
use Modules\Platform\Contracts\Tenancy\AdmissionTicket;

/**
 * Returned once to the inviter's flow so the link can be emailed. The token
 * travels inside an AdmissionTicket (never serialized, printed or logged);
 * only its hash is stored.
 */
final readonly class InvitationIssued
{
    public function __construct(
        public string $invitationId,
        public DateTimeImmutable $expiresAt,
        public AdmissionTicket $ticket,
    ) {}
}
