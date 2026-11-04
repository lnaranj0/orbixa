<?php

namespace Modules\Platform\Contracts\Tenancy;

use LogicException;
use SensitiveParameter;

/**
 * Secret presented to join an organization without an existing membership
 * (an invitation token). It cannot be serialized, printed or dumped, so it
 * never reaches logs, queues, caches or exception traces.
 */
final class AdmissionTicket
{
    private function __construct(private readonly string $secret) {}

    public static function fromSecret(#[SensitiveParameter] string $secret): self
    {
        if ($secret === '' || strlen($secret) > 512) {
            throw new \InvalidArgumentException('Invalid admission ticket.');
        }

        return new self($secret);
    }

    public function reveal(): string
    {
        return $this->secret;
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['secret' => '[redacted]'];
    }

    public function __toString(): string
    {
        return '[redacted]';
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('Admission tickets must never be serialized.');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Admission tickets must never be serialized.');
    }
}
