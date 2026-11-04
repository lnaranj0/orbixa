<?php

namespace Modules\Platform\Tests\Unit;

use InvalidArgumentException;
use LogicException;
use Modules\Platform\Contracts\Tenancy\AdmissionTicket;
use PHPUnit\Framework\TestCase;

/**
 * Invitation secrets must never reach logs, queues, caches or dumps.
 */
final class AdmissionTicketTest extends TestCase
{
    private const SECRET = 'super-secret-invitation-token';

    public function test_secret_is_available_only_through_reveal(): void
    {
        $this->assertSame(self::SECRET, AdmissionTicket::fromSecret(self::SECRET)->reveal());
    }

    public function test_secret_never_appears_when_printed_or_dumped(): void
    {
        $ticket = AdmissionTicket::fromSecret(self::SECRET);

        $this->assertStringNotContainsString(self::SECRET, (string) $ticket);
        $this->assertStringNotContainsString(self::SECRET, print_r($ticket, true));
        ob_start();
        var_dump($ticket);
        $this->assertStringNotContainsString(self::SECRET, (string) ob_get_clean());
    }

    public function test_ticket_cannot_be_serialized(): void
    {
        $this->expectException(LogicException::class);

        serialize(AdmissionTicket::fromSecret(self::SECRET));
    }

    public function test_empty_or_oversized_secret_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AdmissionTicket::fromSecret('');
    }
}
