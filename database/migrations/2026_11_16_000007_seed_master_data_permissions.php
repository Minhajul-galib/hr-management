<?php

use App\Support\MasterData\MasterDataRegistry;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Existing installs never re-run RolePermissionSeeder, so the master-data
     * permissions (`view <master>` / `manage <master>`) are provisioned here.
     * Roles that don't exist yet are left to the seeder.
     */
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (MasterDataRegistry::permissionNames() as $name) {
            Permission::findOrCreate($name);
        }

        // HR owns the master data; junior HR can look but not change it.
        Role::where('name', 'Super System Admin')->first()?->givePermissionTo(MasterDataRegistry::permissionNames());
        Role::where('name', 'HR Manager')->first()?->givePermissionTo(MasterDataRegistry::permissionNames());
        Role::where('name', 'HR Executive')->first()?->givePermissionTo(MasterDataRegistry::viewPermissionNames());

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::whereIn('name', MasterDataRegistry::permissionNames())->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
