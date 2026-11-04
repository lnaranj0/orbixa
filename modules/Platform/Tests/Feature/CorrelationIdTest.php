<?php

namespace Modules\Platform\Tests\Feature;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\Platform\Contracts\Observability\CorrelationId;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * correlation_id on HTTP requests (00 task 4). Uses the health route, which
 * touches no database.
 */
final class CorrelationIdTest extends TestCase
{
    public function test_request_without_header_gets_a_generated_uuid(): void
    {
        $response = $this->get('/up');

        $id = $response->headers->get(CorrelationId::HEADER);
        $this->assertTrue(Str::isUuid($id));
    }

    public function test_valid_inbound_header_is_propagated(): void
    {
        $inbound = (string) Str::uuid7();

        $this->get('/up', [CorrelationId::HEADER => strtoupper($inbound)])
            ->assertHeader(CorrelationId::HEADER, $inbound);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidHeaders(): array
    {
        return [
            'garbage' => ['abc'],
            'injection attempt' => ["x\r\nSet-Cookie: a=b"],
            'too long' => [str_repeat('a', 500)],
        ];
    }

    #[DataProvider('invalidHeaders')]
    public function test_invalid_inbound_header_is_replaced_not_echoed(string $inbound): void
    {
        $id = $this->get('/up', [CorrelationId::HEADER => $inbound])->headers->get(CorrelationId::HEADER);

        $this->assertNotSame($inbound, $id);
        $this->assertTrue(Str::isUuid($id));
    }

    public function test_correlation_id_is_available_in_context_during_the_request(): void
    {
        Route::get('/__correlation-probe', fn () => ['context' => Context::get(CorrelationId::CONTEXT_KEY)]);

        $response = $this->get('/__correlation-probe');

        $response->assertJson(['context' => $response->headers->get(CorrelationId::HEADER)]);
    }
}
