<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Inertia\Inertia;
use App\Models\User;
use App\Notifications\RolePermissionUpdatedNotification;
use App\Support\AuditPermissions;
use App\Support\MasterData\MasterDataRegistry;
use Illuminate\Support\Carbon;

class RoleController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        // Ensure core permissions exist so they appear in the Permission Matrix
        $basePermissions = [
            'view dashboard',
            'view calendar',
            'access todo',
            'manage holidays',
            'view documentation',
            'manage suppliers',
            'view suppliers',
            'create suppliers',
            'edit suppliers',
            'delete suppliers',
            'manage supplier types',
            'view supplier types',
            'create supplier types',
            'edit supplier types',
            'delete supplier types',
            'manage units',
            'view units',
            'create units',
            'edit units',
            'delete units',
            'manage particulars',
            'view particulars',
            'create particulars',
            'edit particulars',
            'delete particulars',
            'manage supplier particular connections',
            'view ledger',
            'track suppliers',
            'view bills',
            'manage payment tracking',
            'manage documents',
            'manage users',
            'manage roles',
            'view attendance',
            'view leave requests',
            'manage payroll',
            'manage departments',
            'view reports',
            'manage notification settings',
            'manage settings',
            'manage document statuses',
            // Workflow Permissions
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
            // Dashboard Widget Permissions
            'dashboard view total users',
            'dashboard view financial summary',
            'dashboard view debit credit chart',
            'dashboard view unit chart',
            'dashboard view top suppliers',
            'dashboard view supplier grades',
            'dashboard view threshold credit summary',
            'dashboard view recent activities',
            'dashboard view total documents',
        ];
        foreach (array_merge($basePermissions, MasterDataRegistry::allPermissionNames(), AuditPermissions::names()) as $perm) {
            Permission::firstOrCreate(['name' => $perm]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Ensure Super System Admin role exists and explicitly has all permissions
        $allPermissions = Permission::all();
        $superRole = Role::firstOrCreate(['name' => 'Super System Admin']);
        if ($allPermissions->count() > 0) {
            $superRole->syncPermissions($allPermissions);
        }

        return Inertia::render('SuperAdmin/Roles/Index', [
            'roles' => Role::with('permissions')->get(),
            'permissions' => Permission::all(),
            // Every master-data screen, for the permission matrix. Built from the
            // registry so a new master shows up there without touching the page.
            'masterData' => $this->masterDataMatrix(),
            // The Compliance group of the sidebar: Internal Audit and what a role may do in it.
            'compliance' => $this->complianceMatrix(),
        ]);
    }

    /**
     * One entry per Compliance system (today Internal Audit), shaped like a master-data entry:
     * the permission behind each action, and `manage`, the full-access one. Add a system here
     * and it shows up in the matrix without touching the page.
     *
     * @return list<array{label: string, actions: list<array{action: string, permission: string, label?: string}>, manage: string}>
     */
    private function complianceMatrix(): array
    {
        $labels = [
            'create' => 'Create audit (incl. follow-up)',
            'edit' => 'Edit draft audit',
            'delete' => 'Delete draft audit',
            'complete' => 'Complete audit (locks the report)',
            'reopen' => 'Reopen completed audit',
            'cap' => 'Update corrective action plan (CAP)',
        ];

        return [[
            'label' => 'Internal Audit',
            'actions' => collect([...AuditPermissions::ACTIONS, 'cap'])
                ->map(fn (string $action) => array_filter([
                    'action' => $action,
                    'permission' => AuditPermissions::permission($action),
                    'label' => $labels[$action] ?? null,
                ]))->values()->all(),
            'manage' => AuditPermissions::MANAGE,
        ]];
    }

    /**
     * One entry per master-data screen, in the sidebar's order (Departments, Units,
     * the rest, Holidays): the permission behind each of its actions, and `manage`,
     * the full-access permission that stands in for all of them.
     *
     * @return list<array{label: string, actions: list<array{action: string, permission: string, label?: string}>, manage: string}>
     */
    private function masterDataMatrix(): array
    {
        $registry = collect(MasterDataRegistry::all())->map(fn (array $master) => [
            'label' => $master['label'],
            'permission' => $master['permission'],
            'without' => isset($master['import']) ? [] : ['import', 'export'],
        ])->values();

        // Units and Holidays predate the registry (own screens) but use the same permissions.
        // A unit's Excel import is part of adding units, so it has no permission of its own.
        $units = ['label' => 'Units', 'permission' => 'units', 'without' => ['import', 'export'], 'labels' => ['create' => 'Add (includes Excel import)']];
        $holidays = ['label' => 'Holidays', 'permission' => MasterDataRegistry::HOLIDAYS, 'without' => ['export']];

        return collect([$registry->first(), $units, ...$registry->slice(1), $holidays])
            ->filter()
            ->map(fn (array $master) => [
                'label' => $master['label'],
                'actions' => collect(array_diff(MasterDataRegistry::ACTIONS, $master['without']))
                    ->map(fn (string $action) => array_filter([
                        'action' => $action,
                        'permission' => "{$action} {$master['permission']}",
                        'label' => $master['labels'][$action] ?? null,
                    ]))->values()->all(),
                'manage' => "manage {$master['permission']}",
            ])->values()->all();
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|unique:roles,name',
            'display_name' => 'nullable|string|max:100',
        ]);

        Role::create([
            'name'         => $request->name,
            'display_name' => $request->display_name ?? null,
        ]);

        return back()->with('success', 'Role created successfully!');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Role $role)
    {
        if ($role->name === 'Super System Admin') {
            return back()->with('error', 'Cannot rename Super System Admin role!');
        }

        if ($role->name === 'Applicant') {
            return back()->with('error', 'Cannot rename Applicant role as it is the default role!');
        }

        $request->validate([
            'name'         => 'required|string|unique:roles,name,' . $role->id,
            'display_name' => 'nullable|string|max:100',
        ]);

        $role->update([
            'name'         => $request->name,
            'display_name' => $request->display_name ?? $role->display_name,
        ]);

        return back()->with('success', 'Role renamed successfully!');
    }

    /**
     * Update the permissions for the specified resource.
     */
    public function updatePermissions(Request $request, Role $role)
    {
        $request->validate([
            'permissions' => 'array',
            'permissions.*' => 'exists:permissions,name', // Validate permission names
        ]);

        // If attempting to update Super System Admin, ensure it always has all permissions
        if ($role->name === 'Super System Admin') {
            $role->syncPermissions(Permission::all());
            // Clear permission cache so frontend sees authoritative changes immediately
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            return back()->with('success', 'Super System Admin retains all permissions.');
        }

        $before = $role->permissions->pluck('name')->map(fn($n) => str_replace('-', ' ', strtolower($n)))->values()->all();
        $role->syncPermissions($request->permissions);
        // Clear the Spatie permission cache so users receive updated permissions immediately
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $after = $role->permissions()->pluck('name')->map(fn($n) => str_replace('-', ' ', strtolower($n)))->values()->all();
        $added = array_values(array_diff($after, $before));
        $removed = array_values(array_diff($before, $after));

        if (count($added) > 0 || count($removed) > 0) {
            $users = User::role($role->name)->get()->unique('id')->values();
            $body = sprintf(
                'Role: %s | Added: %s | Removed: %s',
                $role->name,
                count($added) ? implode(', ', $added) : 'None',
                count($removed) ? implode(', ', $removed) : 'None'
            );

            foreach ($users as $u) {
                $isDuplicate = $u->notifications()
                    ->where('type', RolePermissionUpdatedNotification::class)
                    ->where('created_at', '>=', Carbon::now()->subSeconds(10))
                    ->whereRaw("data::jsonb->>'type' = ?", ['permission_update'])
                    ->whereRaw("data::jsonb->>'role_id' = ?", [$role->id])
                    ->whereRaw("data::jsonb->>'body' = ?", [$body])
                    ->exists();

                if ($isDuplicate) {
                    continue;
                }

                $u->notify(new RolePermissionUpdatedNotification([
                    'user_id' => $u->id,
                    'type' => 'permission_update',
                    'title' => 'Your permissions have been updated',
                    'body' => $body,
                    'role_id' => $role->id,
                    'role_name' => $role->name,
                    'added' => $added,
                    'removed' => $removed,
                    'updated_at' => now()->toIso8601String(),
                ]));
            }
        }

        return back()->with('success', 'Role permissions updated successfully!');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Role $role)
    {
        if ($role->name === 'Super System Admin') {
            return back()->with('error', 'Cannot delete Super System Admin role!');
        }
        
        if ($role->name === 'Applicant') {
            return back()->with('error', 'Cannot delete Applicant role as it is the default role!');
        }
        
        $role->delete();

        return back()->with('success', 'Role deleted successfully!');
    }
}
