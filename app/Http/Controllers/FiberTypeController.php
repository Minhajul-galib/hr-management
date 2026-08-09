<?php
namespace App\Http\Controllers;

use App\Models\FiberType;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FiberTypeController extends Controller
{
    public function index()
    {
        $fiberTypes = FiberType::latest()->get();
        return Inertia::render('FiberTypes/Index', [
            'fiberTypes' => $fiberTypes,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'             => 'required|string|max:255',
            'fiber_description'=> 'nullable|string|max:500',
            'excel_description'=> 'nullable|string|max:255',
            'composition'      => 'nullable|string|max:255',
        ]);
        $validated['created_by'] = auth()->id();
        FiberType::create($validated);
        return back()->with('success', 'Fiber Type created successfully.');
    }

    public function update(Request $request, FiberType $fiberType)
    {
        $validated = $request->validate([
            'name'             => 'required|string|max:255',
            'fiber_description'=> 'nullable|string|max:500',
            'excel_description'=> 'nullable|string|max:255',
            'composition'      => 'nullable|string|max:255',
            'is_active'        => 'boolean',
        ]);
        $fiberType->update($validated);
        return back()->with('success', 'Fiber Type updated successfully.');
    }

    public function destroy(FiberType $fiberType)
    {
        $fiberType->delete();
        return back()->with('success', 'Fiber Type deleted successfully.');
    }
}
