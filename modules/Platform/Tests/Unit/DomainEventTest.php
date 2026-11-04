<?php

namespace Modules\Platform\Tests\Unit;

use InvalidArgumentException;
use Modules\Platform\Contracts\Events\DomainEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DomainEventTest extends TestCase
{
    private const AGGREGATE = '0199f3a2-7c1e-7b4a-9e2d-3c4b5a6d7e8f';

    public function test_valid_event_with_empty_payload(): void
    {
        $event = new DomainEvent('work.item.created', 1, 'work_item', self::AGGREGATE);

        $this->assertSame([], $event->payload);
    }

    /**
     * @return array<string, array{string, int, string, string, array<mixed>}>
     */
    public static function invalidEvents(): array
    {
        return [
            'name without module' => ['created', 1, 'work_item', self::AGGREGATE, []],
            'uppercase name' => ['Work.Item.Created', 1, 'work_item', self::AGGREGATE, []],
            'zero version' => ['work.item.created', 0, 'work_item', self::AGGREGATE, []],
            'non uuid aggregate' => ['work.item.created', 1, 'work_item', '42', []],
            'list payload' => ['work.item.created', 1, 'work_item', self::AGGREGATE, ['a', 'b']],
        ];
    }

    /**
     * @param  array<mixed>  $payload
     */
    #[DataProvider('invalidEvents')]
    public function test_invalid_events_are_rejected(string $name, int $version, string $type, string $aggregate, array $payload): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DomainEvent($name, $version, $type, $aggregate, $payload);
    }
}
