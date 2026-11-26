<?php

namespace App\Http\Controllers;

use App\Models\Unit;
use App\Models\User;
use App\Support\MasterData\Code;
use App\Support\MasterData\MasterDataImporter;
use App\Support\MasterData\MasterDataRegistry;
use Illuminate\Http\Request;
use Inertia\Inertia;

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
            // The Excel import is the same reviewed import the other masters use (see MasterDataController).
            'importDefinition' => $this->importDefinition(),
            'importLimits'    => MasterDataImporter::limits(),
            'importResult'    => session('import_result'), // flashed by the import; only on the request right after it
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
        $request->merge(['unit_code' => Code::normalise($request->input('unit_code'), keepCase: true)]);

        $validated = $request->validate(Unit::rules(), ['unit_code.regex' => Code::UNIT_MESSAGE]);

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
        $request->merge(['unit_code' => Code::normalise($request->input('unit_code'), keepCase: true)]);

        $validated = $request->validate(Unit::rules($unit->id), ['unit_code.regex' => Code::UNIT_MESSAGE]);

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

    /** What the import dialog and review grid need to know about the units sheet. @return array<string, mixed> */
    private function importDefinition(): array
    {
        $definition = MasterDataRegistry::find('units');

        return [
            'key' => 'units',
            'label' => $definition['label'],
            'singular' => $definition['singular'],
            'import' => MasterDataImporter::describe($definition),
        ];
    }
}
