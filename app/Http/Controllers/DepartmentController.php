<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Department;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Spatie\Permission\Models\Role;

class DepartmentController extends Controller
{
    public function index()
    {
        abort_unless(auth()->user()->can('manage departments'), 403);
        // Get departments with users
        $departments = Department::with(['users' => function ($q) {
            $q->with('unit')->select('users.id', 'users.name', 'users.email', 'users.phone', 'users.unit_id', 'users.department_id');
        }])->active()->get();

        $departments = $departments->map(function ($dept) {
            $users = $dept->users->map(fn($u) => [
                'id'        => $u->id,
                'name'      => $u->name,
                'email'     => $u->email,
                'phone'     => $u->phone,
                'unit'      => $u->unit?->name,
                'unit_code' => $u->unit?->unit_code,
            ]);

            return [
                'id'          => $dept->id,
                'name'        => $dept->name,
                'description' => $dept->description,
                'users_count' => $users->count(),
                'users'       => $users,
            ];
        })->values();

        // Users with no department assigned
        $unassignedUsers = User::whereNull('department_id')
            ->with('unit')
            ->get()
            ->map(fn($u) => [
                'id'        => $u->id,
                'name'      => $u->name,
                'email'     => $u->email,
                'phone'     => $u->phone,
                'unit'      => $u->unit?->name,
                'unit_code' => $u->unit?->unit_code,
            ]);

        return Inertia::render('Departments/Index', [
            'departments'     => $departments,
            'unassignedUsers' => $unassignedUsers,
        ]);
    }

    public function store(Request $request)
    {
        abort_unless(auth()->user()->can('manage departments'), 403);

        $validated = $request->validate([
            'name'        => 'required|string|max:255|unique:departments,name',
            'description' => 'nullable|string',
        ]);

        Department::create($validated);

        return redirect()->route('departments.index');
    }

    public function destroy(Department $department)
    {
        abort_unless(auth()->user()->can('manage departments'), 403);

        $department->delete();

        return redirect()->route('departments.index');
    }
}
