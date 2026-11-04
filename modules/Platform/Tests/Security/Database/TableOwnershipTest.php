<?php

namespace Modules\Platform\Tests\Security\Database;

use Illuminate\Support\Facades\DB;
use Modules\Platform\Contracts\Modules\ModuleManifest;
use Modules\Platform\Tests\Security\SecurityTestCase;

/**
 * Spec "Ownership por dominio": every table in the database is declared by
 * exactly one module manifest, and every declared table exists.
 */
final class TableOwnershipTest extends SecurityTestCase
{
    public function test_every_table_has_exactly_one_owning_module(): void
    {
        $owners = [];

        foreach ($this->app->tagged(ModuleManifest::TAG) as $manifest) {
            foreach ($manifest->ownedTables() as $table) {
                $owners[$table][] = $manifest->module();
            }
        }

        $this->assertNotEmpty($owners, 'No module manifests are registered.');

        foreach ($owners as $table => $modules) {
            $this->assertCount(1, $modules, "{$table} is claimed by several modules: ".implode(', ', $modules));
        }

        $tables = array_column(DB::select(<<<'SQL'
            select format('%s.%s', n.nspname, c.relname) as qualified
              from pg_class c join pg_namespace n on n.oid = c.relnamespace
             where c.relkind in ('r', 'p')
               and n.nspname not in ('pg_catalog', 'information_schema', 'pg_toast')
             order by 1
            SQL), 'qualified');

        $this->assertEqualsCanonicalizing(array_keys($owners), $tables, 'Database tables and module manifests differ.');
    }
}
