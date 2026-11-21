<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Existing installs never re-run RolePermissionSeeder (it wipes non-demo
     * users), so the audit permissions and the Auditor role are provisioned
     * here. Roles that don't exist yet are left to the seeder.
     */
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $auditorPermissions = [
            'view dashboard', 'view calendar', 'access todo', 'access clifto talk',
            'view audits', 'manage audits', 'update audit cap',
        ];

        foreach ($auditorPermissions as $name) {
            Permission::findOrCreate($name);
        }

        Role::findOrCreate('Auditor')->givePermissionTo($auditorPermissions);

        $grants = [
            'Super System Admin' => ['view audits', 'manage audits', 'update audit cap'],
            'Top Management' => ['view audits'],
            'HR Manager' => ['view audits', 'update audit cap'],
            'HR Executive' => ['view audits', 'update audit cap'],
        ];

        foreach ($grants as $roleName => $permissions) {
            Role::where('name', $roleName)->first()?->givePermissionTo($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Role::where('name', 'Auditor')->delete();
        Permission::whereIn('name', ['view audits', 'manage audits', 'update audit cap'])->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
