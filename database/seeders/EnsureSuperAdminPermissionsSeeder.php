<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class EnsureSuperAdminPermissionsSeeder extends Seeder
{
    public function run()
    {
        $allPermissions = Permission::all()->pluck('name')->toArray();

        if (empty($allPermissions)) {
            // nothing to sync yet
            return;
        }

        $role = Role::firstOrCreate(['name' => 'Super System Admin']);
        $role->syncPermissions($allPermissions);
    }
}
