<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Departments moved into master data, which is guarded by a `view` and a
     * `manage` permission. `manage departments` already existed; `view
     * departments` is new. Existing installs never re-run the seeder, so the
     * roles get it here, matching the other masters: HR manages, junior HR views.
     */
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::findOrCreate('view departments');
        Permission::findOrCreate('manage departments');

        Role::where('name', 'Super System Admin')->first()?->givePermissionTo(['view departments', 'manage departments']);
        Role::where('name', 'HR Manager')->first()?->givePermissionTo(['view departments', 'manage departments']);
        Role::where('name', 'HR Executive')->first()?->givePermissionTo('view departments');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // `manage departments` predates this migration, so only the new one goes.
        Permission::where('name', 'view departments')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
