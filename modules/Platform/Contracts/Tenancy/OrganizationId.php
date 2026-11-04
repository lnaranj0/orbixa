<?php

namespace Modules\Platform\Contracts\Tenancy;

use InvalidArgumentException;
use Stringable;

/**
 * Validated organization identifier. Never build one from raw client input
 * without passing it through TenantContext, which checks membership first.
 */
final readonly class OrganizationId implements Stringable
{
    private const PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    private function __construct(public string $value) {}

    public static function fromString(string $value): self
    {
        $normalized = strtolower(trim($value));

        if (preg_match(self::PATTERN, $normalized) !== 1) {
            throw new InvalidArgumentException('Invalid organization identifier.');
        }

        return new self($normalized);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
