<?php

namespace Modules\Platform\Presentation\Console;

use Illuminate\Console\Command;
use Modules\Platform\Infrastructure\Events\OutboxRelay;

final class RelayOutboxCommand extends Command
{
    protected $signature = 'orbixa:outbox:relay {--batch=100 : Maximum events per run (1-1000)}';

    protected $description = 'Publish pending transactional outbox events to the queue (relay role, at-least-once)';

    public function handle(OutboxRelay $relay): int
    {
        $result = $relay->relay((int) $this->option('batch'));

        $this->info("Published {$result['published']} event(s); {$result['failed']} failed and will be retried.");

        return self::SUCCESS;
    }
}
