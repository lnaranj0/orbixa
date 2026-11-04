<?php

namespace Modules\Work\Infrastructure;

use Illuminate\Database\DatabaseManager;
use Modules\Work\Contracts\Workflow\StatusUsage;

final class PostgresStatusUsage implements StatusUsage
{
    public function __construct(private readonly DatabaseManager $db) {}

    public function hasItems(string $statusId): bool
    {
        return $this->db->connection()->selectOne('select 1 as found from work.items where workflow_status_id = ?::uuid limit 1', [$statusId]) !== null;
    }
}
