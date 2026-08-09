<?php

namespace App\Http\Controllers;

use App\Models\Unit;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;
use App\Imports\UnitsImport;

class UnitController extends Controller
{
    public function index()
    {
        $showTrashed = request()->boolean('trash');
        $query = Unit::with('createdBy')->latest();
        if ($showTrashed) {
            $query->onlyTrashed();
        }

        // Department overview data (active units with users)
       $departments = Unit::with(['users.roles'])
        ->withCount('users')
        ->where('is_active', true)
        ->orderBy('name')
        ->get()
        ->map(function ($unit) {
            return [
                'id'             => $unit->id,
                'unit_code'      => $unit->unit_code,
                'name'           => $unit->name,
                'short_form'     => $unit->short_form,
                'location'       => $unit->location,
                'contact_person' => $unit->contact_person,
                'phone_number'   => $unit->phone_number,
                'email'          => $unit->email,
                'users_count'    => $unit->users_count,
                'users'          => $unit->users->map(fn($u) => [
                    'id'    => $u->id,
                    'name'  => $u->name,
                    'email' => $u->email,
                    'phone' => $u->phone,
                    'roles' => $u->roles->pluck('name'),
                ]),
            ];
        });
        $unassignedUsers = User::with('roles')->whereNull('unit_id')->get()
            ->map(fn($u) => [
                'id'    => $u->id,
                'name'  => $u->name,
                'email' => $u->email,
                'phone' => $u->phone,
                'roles' => $u->roles->pluck('name'),
            ]);

        return Inertia::render('Units/Index', [
            'units'           => $query->get(),
            'showTrashed'     => $showTrashed,
            'departments'     => $departments,
            'unassignedUsers' => $unassignedUsers,
        ]);
    }

    public function show(Unit $unit)
    {
        return Inertia::render('Units/Show', [
            'unit' => $unit->load('createdBy'),
        ]);
    }

    public function create()
    {
        return Inertia::render('Units/Create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'unit_code' => 'required|string|unique:units,unit_code|max:50',
            'short_form' => 'nullable|string|max:50',
            'name' => 'required|string|max:255',
            'location' => 'nullable|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'phone_number' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'is_active' => 'boolean',
        ]);

        $validated['created_by'] = auth()->id();

        Unit::create($validated);

        return redirect()->route('units.index')->with('success', 'Unit created successfully.');
    }

    public function edit(Unit $unit)
    {
        return Inertia::render('Units/Edit', [
            'unit' => $unit,
        ]);
    }

    public function update(Request $request, Unit $unit)
    {
        $validated = $request->validate([
            'unit_code' => 'required|string|max:50|unique:units,unit_code,' . $unit->id,
            'short_form' => 'nullable|string|max:50',
            'name' => 'required|string|max:255',
            'location' => 'nullable|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'phone_number' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'is_active' => 'boolean',
        ]);

        $unit->update($validated);

        return redirect()->route('units.index')->with('success', 'Unit updated successfully.');
    }

    public function destroy(Unit $unit)
    {
        $unit->delete();
        return back()->with('success', 'Unit moved to Trash.');
    }
    
    public function restore($id)
    {
        $unit = Unit::withTrashed()->findOrFail($id);
        $unit->restore();
        return back()->with('success', 'Unit restored successfully.');
    }
    
    public function forceDestroy($id)
    {
        $unit = Unit::withTrashed()->findOrFail($id);
        $unit->forceDelete();
        return back()->with('success', 'Unit deleted permanently.');
    }

    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimetypes:text/csv,text/plain,application/csv,text/comma-separated-values,application/excel,application/vnd.ms-excel,application/vnd.msexcel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet|max:2048',
        ]);

        try {
            Excel::import(new UnitsImport, $request->file('file'));
            return back()->with('success', 'Units imported successfully.');
        } catch (\Exception $e) {
            return back()->with('error', 'Import failed: ' . $e->getMessage());
        }
    }

    public function downloadTemplate()
    {
        $headers = [
            'unit_code',
            'short_form',
            'name',
            'location',
            'contact_person',
            'phone_number',
            'email',
            'is_active',
        ];

        $sampleRows = [
            [
                'UNIT001',
                'HO',
                'Head Office',
                'Dhaka, Bangladesh',
                'John Doe',
                '01712345678',
                'headoffice@example.com',
                '1',
            ],
            [
                'UNIT002',
                'CTG',
                'Branch Office - Chittagong',
                'Chittagong, Bangladesh',
                'Jane Smith',
                '01798765432',
                'ctg@example.com',
                '1',
            ],
            [
                'UNIT003',
                'GZP',
                'Warehouse - Gazipur',
                'Gazipur, Bangladesh',
                'Mike Johnson',
                '01612345678',
                'warehouse@example.com',
                '1',
            ],
        ];

        $filename = 'unit_import_template.csv';

        $callback = function () use ($headers, $sampleRows) {
            $out = fopen('php://output', 'w');
            // BOM for Excel compatibility
            fwrite($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($out, $headers);

            foreach ($sampleRows as $row) {
                fputcsv($out, $row);
            }

            fclose($out);
        };

        return response()->streamDownload($callback, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
