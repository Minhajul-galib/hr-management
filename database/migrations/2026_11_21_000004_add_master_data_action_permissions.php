<?php

use App\Support\MasterData\MasterDataRegistry;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Every master used to have two permissions, `view <master>` and `manage <master>`.
     * Each now has one per action: view, create, edit, delete, and import / export where
     * the master has Excel. `manage` stays and now means full access, so a role that holds
     * it keeps doing everything it did. Holidays (which only had `manage holidays`) get the
     * same set.
     *
     * Nobody loses anything: a role that could view a master could also export it, so it
     * gets the new `export <master>`. Roles that only had `manage` need nothing.
     */
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (MasterDataRegistry::allPermissionNames() as $name) {
            Permission::findOrCreate($name);
        }

        // Viewers could always export; keep it that way until an admin unticks it.
        foreach (MasterDataRegistry::all() as $master) {
            if (! isset($master['import'])) {
                continue;
            }

            $export = Permission::findOrCreate("export {$master['permission']}");
            foreach (Permission::findOrCreate("view {$master['permission']}")->roles as $role) {
                $role->givePermissionTo($export);
            }
        }

        // The Editor enters all master data (not Holidays, which was never in its list).
        Role::where('name', 'Editor')->first()?->givePermissionTo(MasterDataRegistry::permissionNames());
        Role::where('name', 'Super System Admin')->first()?->givePermissionTo(MasterDataRegistry::allPermissionNames());

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Only what this migration added: `view` and `manage` of the registry masters, and
        // `manage holidays`, existed before it.
        $added = array_filter(
            MasterDataRegistry::allPermissionNames(),
            fn (string $name) => ! str_starts_with($name, 'manage ')
                && ! (str_starts_with($name, 'view ') && ! str_ends_with($name, ' holidays')),
        );

        Permission::whereIn('name', $added)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
