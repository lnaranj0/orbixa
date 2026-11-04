<?php

namespace Modules\Work\Infrastructure;

use Illuminate\Database\DatabaseManager;
use Modules\Work\Contracts\ItemCategories;

final class PostgresItemCategories implements ItemCategories
{
    public function __construct(private readonly DatabaseManager $db) {}

    public function of(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }
        $result = [];
        foreach ($this->db->connection()->select('select id::text as id, status from work.items where id = any(?::uuid[])', [ItemStore::pgArray($itemIds)]) as $row) {
            $c = (array) $row;
            $result[(string) $c['id']] = (string) $c['status'];
        }

        return $result;
    }
}
