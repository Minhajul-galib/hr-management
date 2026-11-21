<?php

use App\Support\MasterData\MasterDataRegistry;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /** The only roles the system keeps. Everything else is removed. */
    private const KEEP = ['Super System Admin', 'Applicant', 'Editor', 'Top Management'];

    /** Units predate the master-data registry and have their own permission set. */
    private const UNIT_PERMISSIONS = ['manage units', 'view units', 'create units', 'edit units', 'delete units'];

    /**
     * Phase 1 of the HR build is entering master data. The Editor does that, so
     * the role gets full access to every master (view + manage), plus Units, plus
     * `view dashboard` (the page every login lands on).
     *
     * Roles outside KEEP are deleted. A user left with no role at all would be
     * stranded, so they get the default "Applicant" role, the same one a new
     * user starts with.
     */
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $editorPermissions = [...MasterDataRegistry::permissionNames(), ...self::UNIT_PERMISSIONS, 'view dashboard'];
        foreach ($editorPermissions as $name) {
            Permission::findOrCreate($name);
        }

        // Additive: if someone already made an "Editor" role, keep what it has.
        Role::findOrCreate('Editor')->givePermissionTo($editorPermissions);
        $applicant = Role::findOrCreate('Applicant');

        foreach (Role::whereNotIn('name', self::KEEP)->get() as $role) {
            foreach ($role->users()->get() as $user) {
                $user->removeRole($role);

                if ($user->roles()->count() === 0) {
                    $user->assignRole($applicant);
                }
            }

            $role->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // The deleted roles (and who held them) are not recoverable here; re-create
        // them by hand if ever needed. Only the role this migration added goes.
        Role::where('name', 'Editor')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
