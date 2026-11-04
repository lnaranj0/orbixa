<?php

namespace Modules\Portfolio\Infrastructure;

use Illuminate\Database\DatabaseManager;
use Modules\Portfolio\Contracts\Catalog\ProjectCatalog;

final class PostgresProjectCatalog implements ProjectCatalog
{
    public function __construct(private readonly DatabaseManager $db) {}

    public function idsByConfidentiality(): array
    {
        $result = ['standard' => [], 'restricted' => []];
        foreach ($this->db->connection()->select('select id::text as id, confidentiality from portfolio.projects order by id') as $row) {
            $c = (array) $row;
            // Fail closed: anything not explicitly standard is treated as restricted.
            $result[$c['confidentiality'] === 'standard' ? 'standard' : 'restricted'][] = (string) $c['id'];
        }

        return $result;
    }
}
