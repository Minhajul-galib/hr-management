<?php
namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\BookingLc;
use App\Models\BookingShipment;
use App\Models\YarnSupplier;
use App\Models\MerchantTeam;
use App\Models\YarnCount;
use App\Models\FiberType;
use App\Models\BookingType;
use App\Models\Unit;
use App\Events\BookingUpdated;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BookingController extends Controller
{
    public function index(Request $request)
    {
        $query = Booking::with([
            'supplier', 'merchantTeam', 'yarnCount',
            'fiberType', 'bookingType', 'unit',
            'lc', 'shipment',
        ]);

        // Filter by fiscal_year
        if ($request->fiscal_year) {
            $query->where('fiscal_year', $request->fiscal_year);
        }

        // Filter by supplier
        if ($request->supplier_id) {
            $query->where('supplier_id', $request->supplier_id);
        }

        // Filter by status
        if ($request->status) {
            $query->where('status', $request->status);
        }

        // Filter by unit
        if ($request->unit_id) {
            $query->where('unit_id', $request->unit_id);
        }

        $bookings = $query->latest()->paginate(50);

        return Inertia::render('Bookings/Index', [
            'bookings'     => $bookings,
            'suppliers'    => YarnSupplier::where('is_active', true)->get(['id', 'name', 'code']),
            'merchantTeams'=> MerchantTeam::where('is_active', true)->get(['id', 'name']),
            'yarnCounts'   => YarnCount::where('is_active', true)->get(['id', 'count_value']),
            'fiberTypes'   => FiberType::where('is_active', true)->get(['id', 'name', 'composition']),
            'bookingTypes' => BookingType::where('is_active', true)->get(['id', 'code', 'name']),
            'units'        => Unit::where('is_active', true)->get(['id', 'name', 'short_form']),
            'filters'      => $request->only(['fiscal_year', 'supplier_id', 'status', 'unit_id']),
            'fiscalYears'  => Booking::distinct()->pluck('fiscal_year')->sortDesc(),
        ]);
    }

    public function create()
    {
        return Inertia::render('Bookings/Create', [
            'suppliers'    => YarnSupplier::where('is_active', true)->get(['id', 'name', 'code']),
            'merchantTeams'=> MerchantTeam::where('is_active', true)->get(['id', 'name']),
            'yarnCounts'   => YarnCount::where('is_active', true)->get(['id', 'count_value']),
            'fiberTypes'   => FiberType::where('is_active', true)->get(['id', 'name', 'composition']),
            'bookingTypes' => BookingType::where('is_active', true)->get(['id', 'code', 'name']),
            'units'        => Unit::where('is_active', true)->get(['id', 'name', 'short_form']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'fiscal_year'      => 'required|integer|min:2000|max:2100',
            'supplier_id'      => 'required|exists:yarn_suppliers,id',
            'merchant_team_id' => 'nullable|exists:merchant_teams,id',
            'yarn_count_id'    => 'nullable|exists:yarn_counts,id',
            'fiber_type_id'    => 'nullable|exists:fiber_types,id',
            'booking_type_id'  => 'nullable|exists:booking_types,id',
            'unit_id'          => 'nullable|exists:units,id',
            'order_qty_kg'     => 'required|numeric|min:0',
            'pi_number'        => 'nullable|string|max:100',
            'pi_received_date' => 'nullable|date',
            'status'           => 'nullable|string|max:50',

            // LC
            'lc_number'          => 'nullable|string|max:100',
            'lc_issue_date'      => 'nullable|date',
            'lc_factory'         => 'nullable|string|max:50',
            'commercial_invoice' => 'nullable|string|max:100',

            // Shipment
            'bl_qty_ctn'        => 'nullable|numeric|min:0',
            'org_document_date' => 'nullable|date',
            'ex_factory_date'   => 'nullable|date',
            'etd'               => 'nullable|date',
            'eta'               => 'nullable|date',
            'received_qty'      => 'nullable|numeric|min:0',
            'received_date'     => 'nullable|date',
            'remark'            => 'nullable|string',
        ]);

        $validated['created_by'] = auth()->id();
        $validated['status'] = $validated['status'] ?? 'pending';

        $booking = Booking::create($validated);
        event(new BookingUpdated($booking));

        // Create LC if provided
        if ($request->lc_number || $request->lc_issue_date) {
            BookingLc::create([
                'booking_id'         => $booking->id,
                'lc_number'          => $request->lc_number,
                'lc_issue_date'      => $request->lc_issue_date,
                'lc_factory'         => $request->lc_factory,
                'commercial_invoice' => $request->commercial_invoice,
            ]);
        }

        // Create Shipment if provided
        if ($request->received_qty || $request->etd) {
            BookingShipment::create([
                'booking_id'        => $booking->id,
                'bl_qty_ctn'        => $request->bl_qty_ctn,
                'org_document_date' => $request->org_document_date,
                'ex_factory_date'   => $request->ex_factory_date,
                'etd'               => $request->etd,
                'eta'               => $request->eta,
                'received_qty'      => $request->received_qty ?? 0,
                'received_date'     => $request->received_date,
                'remark'            => $request->remark,
            ]);
        }

        return redirect()->route('bookings.index')->with('success', 'Booking created successfully.');
    }

    public function show(Booking $booking)
    {
        return Inertia::render('Bookings/Show', [
            'booking' => $booking->load([
                'supplier', 'merchantTeam', 'yarnCount',
                'fiberType', 'bookingType', 'unit',
                'lc', 'shipment', 'createdBy',
            ]),
        ]);
    }

    public function edit(Booking $booking)
    {
        return Inertia::render('Bookings/Edit', [
            'booking'      => $booking->load(['lc', 'shipment']),
            'suppliers'    => YarnSupplier::where('is_active', true)->get(['id', 'name', 'code']),
            'merchantTeams'=> MerchantTeam::where('is_active', true)->get(['id', 'name']),
            'yarnCounts'   => YarnCount::where('is_active', true)->get(['id', 'count_value']),
            'fiberTypes'   => FiberType::where('is_active', true)->get(['id', 'name', 'composition']),
            'bookingTypes' => BookingType::where('is_active', true)->get(['id', 'code', 'name']),
            'units'        => Unit::where('is_active', true)->get(['id', 'name', 'short_form']),
        ]);
    }

    public function update(Request $request, Booking $booking)
    {
        $validated = $request->validate([
            'fiscal_year'      => 'required|integer|min:2000|max:2100',
            'supplier_id'      => 'required|exists:yarn_suppliers,id',
            'merchant_team_id' => 'nullable|exists:merchant_teams,id',
            'yarn_count_id'    => 'nullable|exists:yarn_counts,id',
            'fiber_type_id'    => 'nullable|exists:fiber_types,id',
            'booking_type_id'  => 'nullable|exists:booking_types,id',
            'unit_id'          => 'nullable|exists:units,id',
            'order_qty_kg'     => 'required|numeric|min:0',
            'pi_number'        => 'nullable|string|max:100',
            'pi_received_date' => 'nullable|date',
            'status'           => 'nullable|string|max:50',

            // LC
            'lc_number'          => 'nullable|string|max:100',
            'lc_issue_date'      => 'nullable|date',
            'lc_factory'         => 'nullable|string|max:50',
            'commercial_invoice' => 'nullable|string|max:100',

            // Shipment
            'bl_qty_ctn'        => 'nullable|numeric|min:0',
            'org_document_date' => 'nullable|date',
            'ex_factory_date'   => 'nullable|date',
            'etd'               => 'nullable|date',
            'eta'               => 'nullable|date',
            'received_qty'      => 'nullable|numeric|min:0',
            'received_date'     => 'nullable|date',
            'remark'            => 'nullable|string',
        ]);

        $booking->update($validated);
        event(new BookingUpdated($booking));

        // Update or create LC
        $booking->lc()->updateOrCreate(
            ['booking_id' => $booking->id],
            [
                'lc_number'          => $request->lc_number,
                'lc_issue_date'      => $request->lc_issue_date,
                'lc_factory'         => $request->lc_factory,
                'commercial_invoice' => $request->commercial_invoice,
            ]
        );

        // Update or create Shipment
        $booking->shipment()->updateOrCreate(
            ['booking_id' => $booking->id],
            [
                'bl_qty_ctn'        => $request->bl_qty_ctn,
                'org_document_date' => $request->org_document_date,
                'ex_factory_date'   => $request->ex_factory_date,
                'etd'               => $request->etd,
                'eta'               => $request->eta,
                'received_qty'      => $request->received_qty ?? 0,
                'received_date'     => $request->received_date,
                'remark'            => $request->remark,
            ]
        );

        return redirect()->route('bookings.index')->with('success', 'Booking updated successfully.');
    }

    public function destroy(Booking $booking)
    {
        $booking->delete();
        return back()->with('success', 'Booking deleted successfully.');
    }
}


