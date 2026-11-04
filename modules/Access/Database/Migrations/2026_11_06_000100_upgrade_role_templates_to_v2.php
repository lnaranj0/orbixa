<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 04-monitoring-access (M3 option A, M4): upgrade the base roles of every
 * existing organization from template version 1 to 2 by adding the
 * monitoring permissions. New organizations get them from RoleTemplates.
 *
 * access.roles and access.role_permissions FORCE row level security with a
 * policy for the runtime role only, so the owner role sees no rows. Inside
 * this migration's transaction (Laravel wraps PostgreSQL migrations in one)
 * FORCE is lifted, the rows are inserted and FORCE is restored; any failure
 * rolls everything back, including the ALTERs.
 *
 * The permission list is written out here on purpose: a migration must not
 * change meaning when RoleTemplates evolves later.
 *
 * Must run as the owner role (orbixa_migrator).
 */
return new class extends Migration
{
    public $withinTransaction = true;

    private const V2_ADDITIONS = [
        'org_owner' => ['monitoring.view', 'monitoring.grants.view', 'monitoring.grants.manage', 'monitoring.restricted.view'],
        'org_admin' => ['monitoring.view', 'monitoring.grants.view', 'monitoring.grants.manage', 'monitoring.restricted.view'],
        'viewer' => ['monitoring.view'],
    ];

    public function up(): void
    {
        if (DB::transactionLevel() === 0 && ! DB::pretending()) {
            throw new RuntimeException('This migration must run inside a transaction.');
        }

        DB::statement('alter table access.roles no force row level security');
        DB::statement('alter table access.role_permissions no force row level security');

        $values = [];
        $bindings = [];
        foreach (self::V2_ADDITIONS as $role => $permissions) {
            foreach ($permissions as $permission) {
                $values[] = '(?, ?)';
                array_push($bindings, $role, $permission);
            }
        }

        DB::insert(
            'insert into access.role_permissions (organization_id, role_id, permission)
             select r.organization_id, r.id, v.permission
               from access.roles r
               join (values '.implode(', ', $values).') as v(role_key, permission) on v.role_key = r.key
              where r.is_system and r.template_version = 1
             on conflict (organization_id, role_id, permission) do nothing',
            $bindings
        );
        DB::update('update access.roles set template_version = 2 where is_system and template_version = 1');

        DB::statement('alter table access.role_permissions force row level security');
        DB::statement('alter table access.roles force row level security');
    }

    public function down(): void
    {
        throw new RuntimeException('Role template upgrades cannot be rolled back; roll forward instead.');
    }
};
