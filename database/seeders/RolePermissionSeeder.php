<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Models\User;
use App\Models\Department;
use App\Support\MasterData\MasterDataRegistry;
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
            'manage units',
            'view units',
            'create units',
            'edit units',
            'delete units',
            'access todo',
            'view calendar',
            'manage holidays',
            'manage settings',
            'manage notification settings',
            'view documentation',
            'access clifto talk',
            'view audits',
            'manage audits',
            'update audit cap',
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
        foreach (array_merge($permissions, $workflowPermissions, MasterDataRegistry::allPermissionNames()) as $permission) {
            Permission::findOrCreate($permission);
        }

        // ২. রোল তৈরি করা (Title Case এ, যাতে ফ্রন্টএন্ডের সাথে মিলে)
        // Only these four roles exist. "Applicant" is what every new user starts with.
        $roles = [
            'Super System Admin',
            'Applicant',
            'Editor',
            'Top Management',
        ];

        // অপ্রয়োজনীয় legacy রোলগুলো ডাটাবেস থেকে মুছে ফেলা হচ্ছে
        $obsoleteRoles = [
            'Accounts Head',
            'Purchase Head',
            'Accounts Officer',
            'Purchase Officer',
            'DGM',
            'GM',
            // Dropped when the role set was cut down to the four above.
            'HR Manager',
            'HR Executive',
            'Auditor',
            'Employee',
        ];
        $applicant = Role::findOrCreate('Applicant');
        Role::whereIn('name', $obsoleteRoles)->get()->each(function ($role) use ($applicant) {
            $role->users()->each(function ($user) use ($role, $applicant) {
                $user->removeRole($role);

                // Never leave anyone without a role.
                if ($user->roles()->count() === 0) {
                    $user->assignRole($applicant);
                }
            });
            $role->delete();
        });

        foreach ($roles as $roleName) {
            $role = Role::findOrCreate($roleName);

            // ৩. নির্দিষ্ট রোলকে পারমিশন দেওয়া (লজিক অনুযায়ী)
            if ($roleName === 'Super System Admin') {
                $role->syncPermissions(Permission::all());
            } elseif ($roleName === 'Top Management') {
                $role->givePermissionTo([
                    'view dashboard', 'view accounts', 'workflow-ceo_approved', 'workflow-final_approval',
                    'view calendar', 'access todo', 'access clifto talk', 'view audits',
                ]);
            } elseif ($roleName === 'Editor') {
                // Phase 1: the Editor enters all master data, so gets full access to every
                // master (view + manage) and Units. `view dashboard` is the login landing page.
                $role->givePermissionTo([
                    'view dashboard',
                    'manage units', 'view units', 'create units', 'edit units', 'delete units',
                ]);
                $role->givePermissionTo(MasterDataRegistry::permissionNames());
            }
        }

        // A production database holds real people. The demo accounts below all share
        // the password "password", and this seeder also force-deletes every user that
        // is not one of them — so neither half may ever run against production.
        // Roles and permissions above are safe to (re)apply there; stop here.
        if (app()->environment('production')) {
            $this->command?->warn('Production: roles and permissions seeded; demo users skipped.');

            return;
        }

        // ৪. Demo ইউজার তৈরি করা হবে (Super Admin + corporate HR roles)
        $demoUsers = [
            ['role' => 'Super System Admin', 'name' => 'Super System Admin', 'email' => 'admin@erp.com', 'department' => null],
            ['role' => 'Super System Admin', 'name' => 'System Admin', 'email' => 'admin@example.com', 'department' => null],
            ['role' => 'Editor', 'name' => 'Master Data Editor', 'email' => 'editor@erp.com', 'department' => null],
            ['role' => 'Top Management', 'name' => 'Mahbubul Alam', 'email' => 'topmanagement@erp.com', 'department' => 'Top Management'],
        ];

        // Remove all users except the demo accounts above before seeding.
        User::withTrashed()
            ->whereNotIn('email', collect($demoUsers)->pluck('email')->all())
            ->forceDelete();

        foreach ($demoUsers as $u) {
            $departmentId = $u['department']
                ? Department::where('name', $u['department'])->value('id')
                : null;

            $user = User::withTrashed()->updateOrCreate(
                ['email' => $u['email']],
                [
                    'name' => $u['name'],
                    'password' => Hash::make('password'),
                    'deleted_at' => null,
                    'department_id' => $departmentId,
                ]
            );

            if (! $user->hasRole($u['role'])) {
                $user->syncRoles([$u['role']]);
            }

            $this->command->info("Created/updated demo user: {$u['email']} (role: {$u['role']})");
        }

        $this->command->info('Roles, Permissions and demo users seeded successfully!');
    }
}
