<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 05 security review B6: the status history references real statuses of
 * the item's project with the recorded category (composite foreign keys,
 * like work.items). Rows with null status ids (pre-workflow backfill) are
 * not checked (MATCH SIMPLE). Adding the constraints validates the whole
 * existing history; any inconsistent row fails the migration atomically.
 *
 * Must run as the owner role (orbixa_migrator).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            alter table work.item_status_changes add constraint item_status_changes_to_status_fk
                foreign key (organization_id, project_id, to_status_id, to_category)
                references workflow.statuses (organization_id, project_id, id, category)
            SQL);
        DB::statement(<<<'SQL'
            alter table work.item_status_changes add constraint item_status_changes_from_status_fk
                foreign key (organization_id, project_id, from_status_id, from_category)
                references workflow.statuses (organization_id, project_id, id, category)
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Status history constraints are roll-forward only.');
    }
};
