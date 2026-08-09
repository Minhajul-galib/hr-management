<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Inertia\Inertia;
use App\Models\User;
use App\Notifications\RolePermissionUpdatedNotification;
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
            'manage bookings',
            'view bookings',
            'manage yarn suppliers',
            'view yarn suppliers',
            'manage merchandisers',
            'view merchandisers',
            'manage yarn counts',
            'view yarn counts',
            'manage fiber types',
            'view fiber types',
            'manage procurement followup',
            'view procurement followup',
            'create procurement followup',
            'edit procurement followup',
            'delete procurement followup',
            'import procurement followup',
            'export procurement followup',
            'view periodic view',
            'view periodic view rcv date',
            'view pi wise analysis',
            'view supplier order breakdown',
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
        foreach ($basePermissions as $perm) {
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
        ]);
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
