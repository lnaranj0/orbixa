<?php

namespace Modules\Monitoring\Contracts;

use RuntimeException;

/** Item, member or team not covered by the viewer's grants; same as missing or foreign (HTTP 404). */
final class MonitoringNotFound extends RuntimeException
{
    public static function make(): self
    {
        return new self('Not found.');
    }
}
