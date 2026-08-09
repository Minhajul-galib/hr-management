<?php
namespace App\Http\Controllers;

use App\Models\BookingType;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BookingTypeController extends Controller
{
    public function index()
    {
        $bookingTypes = BookingType::latest()->get();
        return Inertia::render('BookingTypes/Index', [
            'bookingTypes' => $bookingTypes,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string|max:10',
            'name' => 'required|string|max:255',
        ]);
        $validated['created_by'] = auth()->id();
        BookingType::create($validated);
        return back()->with('success', 'Booking Type created successfully.');
    }

    public function update(Request $request, BookingType $bookingType)
    {
        $validated = $request->validate([
            'code'      => 'required|string|max:10',
            'name'      => 'required|string|max:255',
            'is_active' => 'boolean',
        ]);
        $bookingType->update($validated);
        return back()->with('success', 'Booking Type updated successfully.');
    }

    public function destroy(BookingType $bookingType)
    {
        $bookingType->delete();
        return back()->with('success', 'Booking Type deleted successfully.');
    }
}
