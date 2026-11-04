<?php

namespace Modules\Platform\Tests\Unit;

use InvalidArgumentException;
use Modules\Platform\Contracts\Tenancy\OrganizationId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OrganizationIdTest extends TestCase
{
    public function test_valid_uuid_is_normalized(): void
    {
        $id = OrganizationId::fromString(' 0199F3A2-7C1E-7B4A-9E2D-3C4B5A6D7E8F ');

        $this->assertSame('0199f3a2-7c1e-7b4a-9e2d-3c4b5a6d7e8f', $id->value);
        $this->assertTrue($id->equals(OrganizationId::fromString('0199f3a2-7c1e-7b4a-9e2d-3c4b5a6d7e8f')));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidValues(): array
    {
        return [
            'empty' => [''],
            'garbage' => ['abc'],
            'sql fragment' => ["' or true --"],
            'missing hyphens' => ['0199f3a27c1e7b4a9e2d3c4b5a6d7e8f'],
            'non hex' => ['0199f3a2-7c1e-7b4a-9e2d-3c4b5a6d7e8z'],
        ];
    }

    #[DataProvider('invalidValues')]
    public function test_invalid_values_are_rejected(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        OrganizationId::fromString($value);
    }
}
