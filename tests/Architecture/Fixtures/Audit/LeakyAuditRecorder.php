<?php

namespace Modules\Audit\Infrastructure;

use Modules\Platform\Infrastructure\Tenancy\PostgresTenantContext;

/**
 * Deliberate boundary violation, analysed only by tests/Architecture:
 * Audit internals depending on Platform internals instead of its Contracts.
 * Never autoloaded and never part of the application.
 */
final class LeakyAuditRecorder
{
    public function __construct(private readonly PostgresTenantContext $tenant) {}
}
