<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        // ১. পারমিশন লিস্ট (আপনার মডিউল অনুযায়ী আরও যোগ করতে পারবেন)
        $permissions = [
            'view dashboard',
            'manage users',
            'view accounts',
            'manage accounts',
            'view hr',
            'manage hr',
            'view audit logs',
            'impersonate users',
            'manage suppliers',
            'view suppliers',
            'create suppliers',
            'edit suppliers',
            'delete suppliers',
            'manage documents',
            'manage roles',
            'manage departments',
            'access todo',
            'view calendar',
            'manage holidays',
            'manage settings',
            'manage notification settings',
            'view documentation',
        ];

        // Workflow permissions
        $workflowPermissions = [
            'workflow-purchase_draft',
            'workflow-purchase_approval',
            'workflow-accounts_audit',
            'workflow-dgm_approved',
            'workflow-gm_approved',
            'workflow-ceo_approved',
            'workflow-voucher_creation',
            'workflow-voucher_audit',
            'workflow-dgm_voucher_approval',
            'workflow-gm_voucher_approval',
            'workflow-final_approval',
            'workflow-check_making',
        ];

        // পারমিশনগুলো ডাটাবেসে সেভ করা
        foreach (array_merge($permissions, $workflowPermissions) as $permission) {
            Permission::findOrCreate($permission);
        }

        // ২. রোল তৈরি করা (Title Case এ, যাতে ফ্রন্টএন্ডের সাথে মিলে)
        $roles = [
            'Super System Admin',
            'Top Management',
        ];

        // অপ্রয়োজনীয় legacy রোলগুলো ডাটাবেস থেকে মুছে ফেলা হচ্ছে
        $obsoleteRoles = [
            'Accounts Head',
            'Purchase Head',
            'Accounts Officer',
            'Purchase Officer',
            'Auditor',
            'DGM',
            'GM',
        ];
        Role::whereIn('name', $obsoleteRoles)->get()->each(function ($role) {
            $role->users()->each(fn ($user) => $user->removeRole($role));
            $role->delete();
        });

        foreach ($roles as $roleName) {
            $role = Role::findOrCreate($roleName);

            // ৩. নির্দিষ্ট রোলকে পারমিশন দেওয়া (লজিক অনুযায়ী)
            if ($roleName === 'Super System Admin') {
                $role->syncPermissions(Permission::all());
            } elseif ($roleName === 'Top Management') {
                $role->givePermissionTo([
                    'view dashboard', 'view accounts', 'workflow-ceo_approved', 'workflow-final_approval'
                ]);
            }
        }

        // Remove all users except the primary demo admins before seeding.
        User::withTrashed()
            ->whereNotIn('email', ['admin@erp.com', 'admin@example.com'])
            ->forceDelete();

        // ৪. Super System Admin ডেমো ইউজার তৈরি করা হবে
        $demoUsers = [
            ['role' => 'Super System Admin', 'name' => 'Super System Admin', 'email' => 'admin@erp.com'],
            ['role' => 'Super System Admin', 'name' => 'System Admin', 'email' => 'admin@example.com'],
        ];

        foreach ($demoUsers as $u) {
            $user = User::withTrashed()->updateOrCreate(
                ['email' => $u['email']],
                ['name' => $u['name'], 'password' => Hash::make('password'), 'deleted_at' => null]
            );

            if (! $user->hasRole($u['role'])) {
                $user->syncRoles([$u['role']]);
            }

            $this->command->info("Created/updated demo user: {$u['email']} (role: {$u['role']})");
        }

        $this->command->info('Roles, Permissions and demo users seeded successfully!');
    }
}
