<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Department;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;

class UserController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $showTrashed = request()->boolean('trash');
        $query = User::with('roles', 'department')->latest();
        if ($showTrashed) {
            $query->onlyTrashed();
        }
        return Inertia::render('Admin/Users/Index', [
            'users' => $query->get(),
            'roles' => Role::all(),
            'departments' => Department::active()->get(),
            'showTrashed' => $showTrashed,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'roles' => 'nullable|array',
            'roles.*' => 'string|exists:roles,name',
            'department_id' => 'nullable|exists:departments,id',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'department_id' => $request->department_id,
        ]);

        if ($request->has('roles')) {
            $user->syncRoles($this->rolesAssignableBy(auth()->user(), $request->input('roles', [])));
        }

        return back()->with('success', 'User created successfully.');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, User $user)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,'.$user->id,
            'roles' => 'nullable|array',
            'roles.*' => 'string|exists:roles,name',
            'department_id' => 'nullable|exists:departments,id',
        ]);

        $user->update([
            'name' => $request->name,
            'email' => $request->email,
            'department_id' => $request->department_id,
        ]);

        if ($request->filled('password')) {
             $request->validate([
                'password' => ['confirmed', Rules\Password::defaults()],
            ]);
            $user->update(['password' => Hash::make($request->password)]);
        }

        if ($request->has('roles')) {
            $user->syncRoles($this->rolesAssignableBy(auth()->user(), $request->input('roles', []), $user));
        }

        return back()->with('success', 'User updated successfully.');
    }

    /**
     * Filter a requested role list down to what the acting user is allowed to grant.
     *
     * Only an existing Super System Admin may grant (or keep) the Super System
     * Admin role on anyone. Without this, the 'manage users' permission alone
     * — which is far more widely granted than the role itself — would let its
     * holder hand themselves (or anyone) full super-admin rights simply by
     * posting `roles: ["Super System Admin"]`, since Spatie's syncRoles()
     * does not itself check the caller's own privilege level.
     */
    private function rolesAssignableBy(User $actor, array $roles, ?User $target = null): array
    {
        if ($actor->hasRole('Super System Admin')) {
            return $roles;
        }

        $roles = array_values(array_filter($roles, fn ($role) => $role !== 'Super System Admin'));

        // Also refuse to silently strip the role from a target who already
        // holds it — a non-super-admin has no business touching it either way.
        if ($target && $target->hasRole('Super System Admin')) {
            $roles[] = 'Super System Admin';
        }

        return array_values(array_unique($roles));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(User $user)
    {
        if ($user->hasRole('Super System Admin') && User::role('Super System Admin')->count() === 1) {
            return back()->with('error', 'Cannot delete the last Super System Admin.');
        }
        
        if ($user->id === auth()->id()) {
            return back()->with('error', 'You cannot delete yourself.');
        }

        $user->delete();
        return back()->with('success', 'User deleted successfully.');
    }

    public function restore($id)
    {
        $user = User::withTrashed()->findOrFail($id);
        if ($user->id === auth()->id()) {
            return back()->with('error', 'You cannot restore yourself from trash here.');
        }
        $user->restore();
        return back()->with('success', 'User restored successfully.');
    }

    public function forceDestroy($id)
    {
        $user = User::withTrashed()->findOrFail($id);
        if ($user->hasRole('Super System Admin') && User::role('Super System Admin')->count() === 1) {
            return back()->with('error', 'Cannot permanently delete the last Super System Admin.');
        }
        if ($user->id === auth()->id()) {
            return back()->with('error', 'You cannot delete yourself.');
        }
        $user->forceDelete();
        return back()->with('success', 'User deleted permanently.');
    }
}
