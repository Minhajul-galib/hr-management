<?php

use App\Support\AuditPermissions;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Internal Audit used to have three permissions: `view audits`, `manage audits` (create,
     * edit, delete, complete and reopen, all at once) and `update audit cap`. Each action now
     * has its own, so a role can be allowed to write drafts but not complete them, or to
     * reopen reports but not delete them.
     *
     * Nobody loses or gains anything: `manage audits` stays and means full access, so a
     * role that held it keeps doing everything; roles with only `view audits` or
     * `update audit cap` are untouched. The new permissions start out held by nobody
     * but the Super System Admin.
     */
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (AuditPermissions::names() as $name) {
            Permission::findOrCreate($name);
        }

        Role::where('name', 'Super System Admin')->first()?->givePermissionTo(AuditPermissions::names());

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Only what this migration added; the original three stay.
        Permission::whereIn('name', AuditPermissions::added())->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
