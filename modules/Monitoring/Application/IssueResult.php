<?php

namespace Modules\Monitoring\Application;

/**
 * Outcome of IssueGrant. A rejection is a result, not an exception, so the
 * audited attempt commits with the tenant transaction; the caller turns it
 * into GrantRejected (422) afterwards.
 */
final readonly class IssueResult
{
    private function __construct(public ?string $grantId) {}

    public static function issued(string $grantId): self
    {
        return new self($grantId);
    }

    public static function rejected(): self
    {
        return new self(null);
    }

    public function wasRejected(): bool
    {
        return $this->grantId === null;
    }
}
