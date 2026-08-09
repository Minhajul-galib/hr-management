<?php

namespace App\Http\Controllers;

use App\Exports\ProcurementFollowupsExport;
use App\Models\ProcurementFollowup;
use App\Models\FiberType;
use App\Models\MerchantTeam;
use App\Models\YarnCount;
use App\Models\YarnSupplier;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

class ProcurementFollowupController extends Controller
{
    private function computeBalanceQty(?float $orderQty, ?float $receivedQty): float
    {
        $o = $orderQty ?? 0.0;
        $r = $receivedQty ?? 0.0;
        return $o - $r;
    }

    private function supplierRating(float $onTimeRate, float $avgDelayDays): int
    {
        if ($onTimeRate >= 90 && $avgDelayDays <= 0) return 5;
        if ($onTimeRate >= 80 && $avgDelayDays <= 3) return 4;
        if ($onTimeRate >= 65 && $avgDelayDays <= 7) return 3;
        if ($onTimeRate >= 50 && $avgDelayDays <= 14) return 2;
        return 1;
    }

    private function applyAnalysisFilters($query, Request $request)
    {
        return $this->applyAnalysisFiltersExcept($query, $request, []);
    }

    private function buildAnalysisBaseQuery(Request $request, Carbon $from, Carbon $to, array $except = [])
    {
        $q = ProcurementFollowup::query()
            ->whereBetween('pi_received_date', [$from->toDateString(), $to->toDateString()]);
        return $this->applyAnalysisFiltersExcept($q, $request, $except);
    }

    private function applyAnalysisFiltersExcept($query, Request $request, array $except = [])
    {
        if (!in_array('fiscal_year', $except) && $request->filled('fiscal_year')) {
            $query->where('fiscal_year', (int) $request->fiscal_year);
        }
        if (!in_array('supplier', $except) && $request->filled('supplier')) {
            $suppliers = array_filter((array) $request->input('supplier', []));
            if (count($suppliers)) {
                $query->whereIn('supplier', $suppliers);
            }
        }
        if (!in_array('yarn_count', $except) && $request->filled('yarn_count')) {
            $counts = array_filter((array) $request->input('yarn_count', []));
            if (count($counts)) {
                $query->whereIn('yarn_count', $counts);
            }
        }
        if (!in_array('fiber', $except) && $request->filled('fiber')) {
            $fibers = array_filter((array) $request->input('fiber', []));
            if (count($fibers)) {
                $query->whereIn('fiber', $fibers);
            }
        }
        if (!in_array('merchant_team', $except) && $request->filled('merchant_team')) {
            $teams = array_filter((array) $request->input('merchant_team', []));
            if (count($teams)) {
                $query->whereIn('merchant_team', $teams);
            }
        }
        if (!in_array('booking_type', $except) && $request->filled('booking_type')) {
            $query->whereRaw('LOWER(booking_type) = ?', [strtolower((string) $request->booking_type)]);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('pi_received_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('pi_received_date', '<=', $request->date_to);
        }
        return $query;
    }
    public function index(Request $request)
    {
        $showTrashed = $request->boolean('trash');

        $query = ProcurementFollowup::query();
        if ($showTrashed) {
            $query->onlyTrashed();
        }

        if ($request->filled('pi_year')) {
            $query->whereYear('pi_received_date', (int) $request->pi_year);
        }

        if ($request->filled('pi_month')) {
            $query->whereMonth('pi_received_date', (int) $request->pi_month);
        }

        if ($request->filled('pi_number')) {
            $query->where('pi_number', 'ILIKE', '%' . $request->pi_number . '%');
        }

        if ($request->filled('fiscal_year')) {
            $query->where('fiscal_year', (int) $request->fiscal_year);
        }

        if ($request->filled('booking_type')) {
            $query->whereRaw('LOWER(booking_type) = ?', [strtolower((string) $request->booking_type)]);
        }

        if ($request->filled('status')) {
            $status = strtolower((string) $request->status);
            if ($status === 'received') {
                $query->where('balance_qty', '<=', 0);
            } elseif ($status === 'pending') {
                $query->where('received_qty', '<=', 0)->where('balance_qty', '>', 0);
            } elseif ($status === 'partial') {
                $query->where('received_qty', '>', 0)->where('balance_qty', '>', 0);
            }
        }

        if ($request->filled('supplier')) {
            $query->where('supplier', $request->supplier);
        }

        if ($request->filled('merchandiser')) {
            $query->where('merchandiser', 'ILIKE', '%' . $request->merchandiser . '%');
        }

        if ($request->filled('fiber')) {
            $query->where('fiber', $request->fiber);
        }

        if ($request->filled('yarn_count')) {
            $query->where('yarn_count', $request->yarn_count);
        }

        if ($request->filled('merchant_team')) {
            $query->where('merchant_team', $request->merchant_team);
        }

        $summary = (clone $query)
            ->reorder()
            ->selectRaw('COUNT(*)::int as total_orders')
            ->selectRaw('COALESCE(SUM(order_qty_kg), 0) as total_order_kg')
            ->selectRaw('COALESCE(SUM(received_qty), 0) as total_received_kg')
            ->selectRaw('COALESCE(SUM(GREATEST(balance_qty, 0)), 0) as total_pending_kg')
            ->first();

        $perPage = (int) $request->input('per_page', 50);
        $perPage = in_array($perPage, [50, 100, 200, 500], true) ? $perPage : 50;

        $followups = $query
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        $completionRate = 0;
        $orderKg = (float) ($summary->total_order_kg ?? 0);
        $receivedKg = (float) ($summary->total_received_kg ?? 0);
        if ($orderKg > 0) {
            $completionRate = min(100, max(0, ($receivedKg / $orderKg) * 100));
        }

        $filterQuery = ProcurementFollowup::query();
        if ($showTrashed) {
            $filterQuery->onlyTrashed();
        }

        return Inertia::render('ProcurementFollowup/Index', [
            'followups' => $followups,
            'filters' => $request->only(['pi_year', 'pi_month', 'pi_number', 'fiscal_year', 'supplier', 'merchandiser', 'fiber', 'yarn_count', 'merchant_team', 'booking_type', 'status', 'per_page']),
            'filterOptions' => [
                'piYears'       => (clone $filterQuery)->whereNotNull('pi_received_date')->selectRaw('DISTINCT EXTRACT(YEAR FROM pi_received_date)::int as y')->orderByDesc('y')->pluck('y')->values(),
                'fiscalYears'   => (clone $filterQuery)->distinct()->pluck('fiscal_year')->filter()->sortDesc()->values(),
                'suppliers'     => (clone $filterQuery)->whereNotNull('supplier')->where('supplier', '!=', '')->distinct()->orderBy('supplier')->pluck('supplier')->values(),
                'merchandisers' => (clone $filterQuery)->whereNotNull('merchandiser')->where('merchandiser', '!=', '')->distinct()->orderBy('merchandiser')->pluck('merchandiser')->values(),
                'fibers'        => (clone $filterQuery)->whereNotNull('fiber')->where('fiber', '!=', '')->distinct()->orderBy('fiber')->pluck('fiber')->values(),
                'yarnCounts'    => (clone $filterQuery)->whereNotNull('yarn_count')->where('yarn_count', '!=', '')->distinct()->orderBy('yarn_count')->pluck('yarn_count')->values(),
                'merchantTeams' => (clone $filterQuery)->distinct()->pluck('merchant_team')->filter()->sort()->values(),
            ],
            'summary' => [
                'total_orders' => (int) ($summary->total_orders ?? 0),
                'total_order_kg' => (float) ($summary->total_order_kg ?? 0),
                'total_received_kg' => (float) ($summary->total_received_kg ?? 0),
                'total_pending_kg' => (float) ($summary->total_pending_kg ?? 0),
                'completion_rate' => (float) $completionRate,
            ],
            'serverToday' => now()->toDateString(),
            'showTrashed' => $showTrashed,
            'yarnSuppliers' => YarnSupplier::where('is_active', true)->select('name', 'code')->orderBy('name')->get(),
            'merchantTeams' => MerchantTeam::where('is_active', true)->select('name', 'code')->orderBy('name')->get(),
            'yarnCounts' => YarnCount::where('is_active', true)->select('count_value', 'code')->orderBy('count_value')->get(),
            'fiberTypes' => FiberType::where('is_active', true)->select('name', 'fiber_description', 'excel_description')->orderBy('name')->get(),
            'merchandiserOptions' => ProcurementFollowup::distinct()->pluck('merchandiser')->filter()->sort()->values(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'pi_number' => 'required|string|max:100',
            'supplier' => 'nullable|string|max:255',
            'merchandiser' => 'nullable|string|max:255',
            'merchant_team' => 'nullable|string|max:255',
            'yarn_count' => 'nullable|string|max:100',
            'booking_type' => 'nullable|string|max:10',
            'fiber_description' => 'nullable|string',
            'fiber' => 'nullable|string|max:255',
            'order_qty_kg' => 'nullable|numeric|min:0',
            'pi_received_date' => 'nullable|date',
            'lc_number' => 'nullable|string|max:100',
            'lc_issue_date' => 'nullable|date',
            'lc_factory' => 'nullable|string|max:50',
            'commercial_invoice' => 'nullable|string|max:100',
            'bl_qty_ctn' => 'nullable|numeric|min:0',
            'org_document_date' => 'nullable|date',
            'ex_factory_date' => 'nullable|date',
            'etd' => 'nullable|date',
            'eta' => 'nullable|date',
            'received_qty' => 'nullable|numeric|min:0',
            'received_date' => 'nullable|date',
            'remark' => 'nullable|string',
        ]);

        if (isset($validated['booking_type']) && $validated['booking_type'] !== null) {
            $bt = strtoupper((string) $validated['booking_type']);
            $validated['booking_type'] = in_array($bt, ['CF', 'CC'], true) ? $bt : $validated['booking_type'];
        }

        $orderQty = isset($validated['order_qty_kg']) ? (float) $validated['order_qty_kg'] : 0.0;
        $rcvQty = isset($validated['received_qty']) ? (float) $validated['received_qty'] : 0.0;
        $validated['balance_qty'] = $this->computeBalanceQty($orderQty, $rcvQty);

        if (!empty($validated['pi_received_date'])) {
            $validated['fiscal_year'] = (int) Carbon::parse($validated['pi_received_date'])->format('Y');
        }

        $validated['import_batch'] = 'manual';

        ProcurementFollowup::create($validated);

        return back()->with('success', 'Procurement followup added successfully.');
    }

    public function update(Request $request, $id)
    {
        $followup = ProcurementFollowup::findOrFail($id);

        $validated = $request->validate([
            'supplier' => 'nullable|string|max:255',
            'merchandiser' => 'nullable|string|max:255',
            'merchant_team' => 'nullable|string|max:255',
            'yarn_count' => 'nullable|string|max:100',
            'booking_type' => 'nullable|string|max:10',
            'fiber_description' => 'nullable|string',
            'fiber' => 'nullable|string|max:255',
            'order_qty_kg' => 'nullable|numeric|min:0',
            'pi_received_date' => 'nullable|date',
            'lc_number' => 'nullable|string|max:100',
            'lc_issue_date' => 'nullable|date',
            'lc_factory' => 'nullable|string|max:50',
            'commercial_invoice' => 'nullable|string|max:100',
            'bl_qty_ctn' => 'nullable|numeric|min:0',
            'org_document_date' => 'nullable|date',
            'ex_factory_date' => 'nullable|date',
            'etd' => 'nullable|date',
            'eta' => 'nullable|date',
            'received_qty' => 'nullable|numeric|min:0',
            'received_date' => 'nullable|date',
            'remark' => 'nullable|string',
        ]);

        if (isset($validated['booking_type']) && $validated['booking_type'] !== null) {
            $bt = strtoupper((string) $validated['booking_type']);
            $validated['booking_type'] = in_array($bt, ['CF', 'CC'], true) ? $bt : $validated['booking_type'];
        }

        $orderQty = isset($validated['order_qty_kg']) ? (float) $validated['order_qty_kg'] : (float) ($followup->order_qty_kg ?? 0);
        $rcvQty = isset($validated['received_qty']) ? (float) $validated['received_qty'] : (float) ($followup->received_qty ?? 0);
        $validated['balance_qty'] = $this->computeBalanceQty($orderQty, $rcvQty);

        if (array_key_exists('pi_received_date', $validated) && !empty($validated['pi_received_date'])) {
            $validated['fiscal_year'] = (int) Carbon::parse($validated['pi_received_date'])->format('Y');
        }

        $followup->update($validated);

        return back()->with('success', 'Procurement followup updated successfully.');
    }

    public function destroy($id)
    {
        $followup = ProcurementFollowup::findOrFail($id);
        $followup->delete();

        return back()->with('success', 'Procurement followup moved to Trash.');
    }

    public function restore($id)
    {
        $followup = ProcurementFollowup::withTrashed()->findOrFail($id);
        $followup->restore();

        return back()->with('success', 'Procurement followup restored successfully.');
    }

    public function forceDestroy($id)
    {
        $followup = ProcurementFollowup::withTrashed()->findOrFail($id);
        $followup->forceDelete();

        return back()->with('success', 'Procurement followup deleted permanently.');
    }

    public function bulkDestroy(Request $request)
    {
        $ids = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ])['ids'];

        ProcurementFollowup::whereIn('id', $ids)->delete();

        return back()->with('success', count($ids) . ' procurement followups moved to Trash.');
    }

    public function bulkRestore(Request $request)
    {
        $ids = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ])['ids'];

        ProcurementFollowup::onlyTrashed()->whereIn('id', $ids)->restore();

        return back()->with('success', count($ids) . ' procurement followups restored successfully.');
    }

    public function bulkForceDestroy(Request $request)
    {
        $ids = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ])['ids'];

        ProcurementFollowup::withTrashed()->whereIn('id', $ids)->forceDelete();

        return back()->with('success', count($ids) . ' procurement followups deleted permanently.');
    }

    public function export(Request $request)
    {
        return Excel::download(new ProcurementFollowupsExport($request->all()), 'procurement_followup_export.xlsx');
    }

    public function yearnOrder(Request $request)
    {
        return Inertia::render('ProcurementFollowup/YearnOrder', [
            'fiscalYears'   => ProcurementFollowup::distinct()->whereNotNull('fiscal_year')->orderByDesc('fiscal_year')->pluck('fiscal_year')->values(),
            'yarnCounts'    => ProcurementFollowup::distinct()->pluck('yarn_count')->filter()->sort()->values(),
            'fiberTypes'    => ProcurementFollowup::distinct()->pluck('fiber')->filter()->sort()->values(),
            'merchantTeams' => ProcurementFollowup::distinct()->pluck('merchant_team')->filter()->sort()->values(),
        ]);
    }

    public function piWiseAnalysis(Request $request)
    {
        # #region debug-point E:pi-wise-analysis-entry
        @file_get_contents('http://127.0.0.1:7777/event', false, stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => json_encode(['sessionId' => 'pi-wise-analysis-500', 'runId' => 'pre-fix', 'hypothesisId' => 'E', 'location' => 'ProcurementFollowupController::piWiseAnalysis:entry', 'msg' => '[DEBUG] piWiseAnalysis controller entered', 'data' => ['url' => $request->fullUrl(), 'user_id' => $request->user()?->id], 'ts' => round(microtime(true) * 1000)])]]));
        # #endregion
        $response = Inertia::render('ProcurementFollowup/PIwiseAnalysis', [
            'fiscalYears'        => ProcurementFollowup::distinct()->whereNotNull('fiscal_year')->orderByDesc('fiscal_year')->pluck('fiscal_year')->values(),
            'yarnCounts'         => ProcurementFollowup::distinct()->pluck('yarn_count')->filter()->sort()->values(),
            'fiberTypes'         => ProcurementFollowup::distinct()->pluck('fiber')->filter()->sort()->values(),
            'merchantTeams'      => ProcurementFollowup::distinct()->pluck('merchant_team')->filter()->sort()->values(),
            'merchantTeamNameMap' => MerchantTeam::get()->flatMap(function ($t) {
                $entries = [];
                if (!empty($t->code))    $entries[$t->code]    = $t->name;
                if (!empty($t->ep_code)) $entries[$t->ep_code] = $t->name;
                return $entries;
            })->toArray(),
        ]);
        # #region debug-point E:pi-wise-analysis-exit
        @file_get_contents('http://127.0.0.1:7777/event', false, stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => json_encode(['sessionId' => 'pi-wise-analysis-500', 'runId' => 'pre-fix', 'hypothesisId' => 'E', 'location' => 'ProcurementFollowupController::piWiseAnalysis:exit', 'msg' => '[DEBUG] piWiseAnalysis controller built Inertia response', 'data' => ['merchant_team_map_count' => MerchantTeam::count()], 'ts' => round(microtime(true) * 1000)])]]));
        # #endregion
        return $response;
    }

    public function piWiseAnalysisData(Request $request)
    {
        return $this->yearnOrderData($request);
    }

    public function yearnOrderData(Request $request)
    {
        $period         = $request->input('period', 'fiscal_year');
        $bookingType    = $request->input('booking_type', '');
        $selectedCounts = array_filter((array) $request->input('yarn_count', []));
        $fiberTypes     = array_filter((array) $request->input('fiber', []));
        $merchantTeams  = array_filter((array) $request->input('merchant_team', []));

        $today = Carbon::today();

        $fyFilter = null;
        if ($period === 'fiscal_year' && $request->filled('fiscal_year')) {
            $fyFilter = (int) $request->input('fiscal_year');
            $from     = Carbon::create($fyFilter, 1, 1)->startOfDay();
            $today    = Carbon::create($fyFilter, 12, 31)->endOfDay();
            if ($today->gt(Carbon::today()->endOfDay())) {
                $today = Carbon::today()->endOfDay();
            }
        } elseif ($period === 'custom' && $request->filled('date_from') && $request->filled('date_to')) {
            $from  = Carbon::parse($request->date_from)->startOfDay();
            $today = Carbon::parse($request->date_to)->endOfDay();
        } else {
            $from = match ($period) {
                '4months' => $today->copy()->subMonths(4)->startOfMonth(),
                '6months' => $today->copy()->subMonths(6)->startOfMonth(),
                default   => $today->copy()->subMonths(12)->startOfMonth(),
            };
        }

        $base = ProcurementFollowup::query()
            ->whereNotNull('pi_received_date')
            ->when($fyFilter, fn ($q) => $q->where('fiscal_year', $fyFilter))
            ->when(!$fyFilter, fn ($q) => $q
                ->whereDate('pi_received_date', '>=', $from->toDateString())
                ->whereDate('pi_received_date', '<=', $today->toDateString())
            );

        if ($bookingType) {
            $base->whereRaw('LOWER(booking_type) = ?', [strtolower($bookingType)]);
        }

        if (count($selectedCounts)) {
            $base->whereIn('yarn_count', $selectedCounts);
        }

        if (count($fiberTypes)) {
            $base->whereIn('fiber', $fiberTypes);
        }

        if (count($merchantTeams)) {
            $base->whereIn('merchant_team', $merchantTeams);
        }

        // Top yarn counts by total order KG in the period (max 15)
        $yarnCounts = (clone $base)
            ->selectRaw("COALESCE(NULLIF(TRIM(yarn_count), ''), '(Unknown)') as yarn_count")
            ->selectRaw('COALESCE(SUM(order_qty_kg), 0) as total_kg')
            ->groupBy('yarn_count')
            ->orderByDesc('total_kg')
            ->limit(15)
            ->pluck('yarn_count');

        // Monthly breakdown per yarn count (all data from base; pivot limits to top counts)
        $rows = (clone $base)
            ->selectRaw("TO_CHAR(pi_received_date, 'YYYY-MM') as ym")
            ->selectRaw("COALESCE(NULLIF(TRIM(yarn_count), ''), '(Unknown)') as yarn_count")
            ->selectRaw('COALESCE(SUM(order_qty_kg), 0) as order_kg')
            ->selectRaw('COUNT(*)::int as pi_count')
            ->groupBy('ym', 'yarn_count')
            ->orderBy('ym')
            ->get();

        // Build full months list for the period
        $months = collect();
        $cur    = $from->copy();
        while ($cur <= $today) {
            $months->push($cur->format('Y-m'));
            $cur->addMonth();
        }

        // Pivot: [yarn_count][ym] => value
        $kgPivot  = [];
        $cntPivot = [];
        foreach ($rows as $row) {
            $kgPivot[$row->yarn_count][$row->ym]  = (float) $row->order_kg;
            $cntPivot[$row->yarn_count][$row->ym] = (int) $row->pi_count;
        }

        // Build series arrays
        $series   = [];
        $totals   = [];
        $piCounts = [];
        foreach ($yarnCounts as $count) {
            $data    = $months->map(fn ($ym) => $kgPivot[$count][$ym] ?? 0.0)->values();
            $piData  = $months->map(fn ($ym) => $cntPivot[$count][$ym] ?? 0)->values();
            $series[]        = ['name' => $count, 'data' => $data];
            $totals[$count]  = round(array_sum($data->toArray()), 3);
            $piCounts[$count] = array_sum($piData->toArray());
        }

        $grandTotal = round(array_sum(array_values($totals)), 3);

        // Monthly grand totals (for peak month detection)
        $monthlyTotals = $months->map(
            fn ($ym) => $yarnCounts->sum(fn ($c) => $kgPivot[$c][$ym] ?? 0.0)
        );
        $peakIdx     = $monthlyTotals->search($monthlyTotals->max());
        $peakMonth   = ($peakIdx !== false && $months->has($peakIdx)) ? $months[$peakIdx] : null;
        $peakMonthKg = ($peakIdx !== false) ? round((float) $monthlyTotals[$peakIdx], 3) : 0;

        $topCount   = $yarnCounts->first();
        $topCountKg = $topCount ? ($totals[$topCount] ?? 0) : 0;

        $totalPiRecords = (clone $base)->count();

        // Base filtered by period + yarn_count only (for booking_type options)
        $baseYarnOnly = ProcurementFollowup::query()
            ->whereNotNull('pi_received_date')
            ->whereDate('pi_received_date', '>=', $from->toDateString())
            ->whereDate('pi_received_date', '<=', $today->toDateString());
        if (count($selectedCounts)) $baseYarnOnly->whereIn('yarn_count', $selectedCounts);

        $availableBookingTypes = (clone $baseYarnOnly)
            ->whereNotNull('booking_type')
            ->whereRaw("TRIM(booking_type) != ''")
            ->selectRaw('UPPER(TRIM(booking_type)) as bt')
            ->groupBy('bt')->orderBy('bt')
            ->pluck('bt')->values();

        // Base filtered by period + yarn_count + booking_type (for fiber + merchant_team options)
        $baseYarnType = ProcurementFollowup::query()
            ->whereNotNull('pi_received_date')
            ->whereDate('pi_received_date', '>=', $from->toDateString())
            ->whereDate('pi_received_date', '<=', $today->toDateString());
        if (count($selectedCounts)) $baseYarnType->whereIn('yarn_count', $selectedCounts);
        if ($bookingType)           $baseYarnType->whereRaw('LOWER(booking_type) = ?', [strtolower($bookingType)]);

        $availableFibers = (clone $baseYarnType)
            ->whereNotNull('fiber')->whereRaw("TRIM(fiber) != ''")
            ->distinct()->orderBy('fiber')
            ->pluck('fiber')->values();

        $availableMerchantTeams = (clone $baseYarnType)
            ->whereNotNull('merchant_team')->whereRaw("TRIM(merchant_team) != ''")
            ->distinct()->orderBy('merchant_team')
            ->pluck('merchant_team')->values();

        $receivedRows = (clone $base)
            ->selectRaw("COALESCE(NULLIF(TRIM(yarn_count), ''), '(Unknown)') as yarn_count")
            ->selectRaw('COALESCE(SUM(received_qty), 0) as received_kg')
            ->groupBy('yarn_count')
            ->get();

        $receivedByCount = $receivedRows->keyBy('yarn_count')
            ->map(fn ($r) => round((float) ($r->received_kg ?? 0), 3));

        $leadRows = (clone $base)
            ->whereNotNull('received_date')
            ->selectRaw("COALESCE(NULLIF(TRIM(yarn_count), ''), '(Unknown)') as yarn_count")
            ->selectRaw("AVG(EXTRACT(EPOCH FROM (received_date::timestamp - pi_received_date::timestamp)) / 86400.0) as avg_days")
            ->groupBy('yarn_count')
            ->get();

        $leadByCount = $leadRows->keyBy('yarn_count')
            ->map(fn ($r) => isset($r->avg_days) ? round((float) $r->avg_days, 1) : null);

        $typeRows = (clone $base)
            ->selectRaw("COALESCE(NULLIF(TRIM(yarn_count), ''), '(Unknown)') as yarn_count")
            ->selectRaw("COALESCE(NULLIF(UPPER(TRIM(booking_type)), ''), '(Unknown)') as bt")
            ->selectRaw('COALESCE(SUM(order_qty_kg), 0) as order_kg')
            ->groupBy('yarn_count', 'bt')
            ->get();

        $typeByCount = $typeRows->groupBy('yarn_count')->map(function ($rows) {
            return $rows->mapWithKeys(fn ($r) => [(string) $r->bt => round((float) ($r->order_kg ?? 0), 3)]);
        });

        $yarnExtras = [];
        foreach ($yarnCounts as $count) {
            $orderKg = (float) ($totals[$count] ?? 0);
            $rcvKg = (float) ($receivedByCount[$count] ?? 0);
            $yarnExtras[$count] = [
                'order_kg' => round($orderKg, 3),
                'received_kg' => round($rcvKg, 3),
                'balance_kg' => round($orderKg - $rcvKg, 3),
                'pi_records' => (int) ($piCounts[$count] ?? 0),
                'avg_lead_days' => $leadByCount[$count] ?? null,
                'type_kg' => $typeByCount[$count] ?? (object) [],
            ];
        }

        return response()->json([
            'period'           => $period,
            'months'           => $months->values(),
            'yarn_counts'      => $yarnCounts->values(),
            'series'           => $series,
            'totals'           => $totals,
            'pi_counts'        => $piCounts,
            'grand_total'      => $grandTotal,
            'total_pi_records' => $totalPiRecords,
            'peak_month'       => $peakMonth,
            'peak_month_kg'    => $peakMonthKg,
            'top_count'        => $topCount,
            'top_count_kg'     => $topCountKg,
            'yarn_extras'      => $yarnExtras,
            'available_fibers'         => $availableFibers,
            'available_booking_types'  => $availableBookingTypes,
            'available_merchant_teams' => $availableMerchantTeams,
        ]);
    }

    public function periodicView(Request $request)
    {
        return Inertia::render('ProcurementFollowup/PeriodicView', [
            'filters' => $request->only(['period', 'date_from', 'date_to', 'yarn_count', 'fiber', 'merchant_team', 'booking_type']),
            'filterOptions' => [
                'fiscalYears'   => ProcurementFollowup::distinct()->whereNotNull('fiscal_year')->orderByDesc('fiscal_year')->pluck('fiscal_year')->values(),
                'yarnCounts'    => ProcurementFollowup::distinct()->pluck('yarn_count')->filter()->sort()->values(),
                'fiberTypes'    => ProcurementFollowup::distinct()->pluck('fiber')->filter()->sort()->values(),
                'merchantTeams' => ProcurementFollowup::distinct()->pluck('merchant_team')->filter()->sort()->values(),
            ],
            'merchantTeamNameMap' => MerchantTeam::get()->flatMap(function ($t) {
                $entries = [];
                if (!empty($t->code))    $entries[$t->code]    = $t->name;
                if (!empty($t->ep_code)) $entries[$t->ep_code] = $t->name;
                return $entries;
            })->toArray(),
        ]);
    }

    public function periodicViewData(Request $request)
    {
        // "All Years" mode: period=fiscal_year with no specific year → use all available data
        if ($request->input('period') === 'fiscal_year' && !$request->filled('fiscal_year')) {
            $earliest = ProcurementFollowup::whereNotNull('pi_received_date')->min('pi_received_date') ?? '2000-01-01';
            $request->merge([
                'period'    => 'custom',
                'date_from' => $earliest,
                'date_to'   => now()->toDateString(),
            ]);
        }

        $baseResponse = $this->analysisData($request);
        $responseData = $baseResponse->getData(true);

        // Resolve the same date range as analysisData
        $period = $request->input('period', '1yr');
        $today  = Carbon::today();
        if ($period === 'fiscal_year' && $request->filled('fiscal_year')) {
            $fy   = (int) $request->input('fiscal_year');
            $from = Carbon::create($fy, 1, 1)->startOfDay();
            $to   = Carbon::create($fy, 12, 31)->endOfDay();
            if ($to->gt(Carbon::today()->endOfDay())) {
                $to = Carbon::today()->endOfDay();
            }
        } elseif ($period === 'custom' && $request->filled('date_from') && $request->filled('date_to')) {
            $from = Carbon::parse($request->date_from)->startOfDay();
            $to   = Carbon::parse($request->date_to)->endOfDay();
        } else {
            $from = $today->copy()->subMonths(12)->startOfMonth();
            $to   = $today->copy()->endOfDay();
        }

        $base = $this->buildAnalysisBaseQuery($request, $from, $to);

        // Yarn count × booking type breakdown for stacked horizontal bar
        $breakdown = (clone $base)
            ->selectRaw("COALESCE(NULLIF(TRIM(yarn_count), ''), '(Unknown)') as yarn_count")
            ->selectRaw("COALESCE(NULLIF(UPPER(TRIM(booking_type)), ''), '(Unknown)') as booking_type")
            ->selectRaw('COALESCE(SUM(order_qty_kg), 0) as order_kg')
            ->groupBy('yarn_count', 'booking_type')
            ->get();

        // Top yarn counts sorted by total KG
        $yarnTotals = $breakdown->groupBy('yarn_count')
            ->map(fn ($rows) => $rows->sum('order_kg'))
            ->sortByDesc(fn ($v) => $v)
            ->take(20)
            ->keys()
            ->values();

        $bookingTypes = $breakdown->pluck('booking_type')->unique()->sort()->values();

        $pivot = $breakdown->groupBy('yarn_count')
            ->mapWithKeys(fn ($rows, $yc) => [$yc => $rows->pluck('order_kg', 'booking_type')]);

        $series = $bookingTypes->map(fn ($bt) => [
            'name' => $bt,
            'data' => $yarnTotals->map(fn ($yc) => (float) ($pivot[$yc][$bt] ?? 0))->values(),
        ])->values();

        $responseData['charts']['yarn_count_type_breakdown'] = [
            'categories' => $yarnTotals,
            'series'     => $series,
        ];

        // Merchant team × yarn count 100% stacked breakdown
        $merchantYarnRows = (clone $base)
            ->whereNotNull('merchant_team')->whereRaw("TRIM(merchant_team) != ''")
            ->selectRaw('TRIM(merchant_team) as merchant_team')
            ->selectRaw("COALESCE(NULLIF(TRIM(yarn_count), ''), '(Unknown)') as yarn_count")
            ->selectRaw('COALESCE(SUM(order_qty_kg), 0) as order_kg')
            ->groupBy('merchant_team', 'yarn_count')
            ->get();

        $merchantTeams = $merchantYarnRows->groupBy('merchant_team')
            ->map(fn ($rows) => $rows->sum('order_kg'))
            ->sortByDesc(fn ($v) => $v)
            ->keys()->values();

        $yarnCountsForMerchant = $merchantYarnRows->pluck('yarn_count')->unique()->sort()->values();

        $merchantYarnPivot = $merchantYarnRows->groupBy('merchant_team')
            ->mapWithKeys(fn ($rows, $mt) => [$mt => $rows->pluck('order_kg', 'yarn_count')]);

        $merchantYarnSeries = $yarnCountsForMerchant->map(fn ($yc) => [
            'name' => $yc,
            'data' => $merchantTeams->map(fn ($mt) => (float) ($merchantYarnPivot[$mt][$yc] ?? 0))->values(),
        ])->values();

        $responseData['charts']['merchant_yarn_breakdown'] = [
            'categories' => $merchantTeams,
            'series'     => $merchantYarnSeries,
        ];

        // Merchant team × yarn count grouped stacked: Ordered vs Received (quantity)
        $merchantYarnQtyRows = (clone $base)
            ->whereNotNull('merchant_team')->whereRaw("TRIM(merchant_team) != ''")
            ->selectRaw('TRIM(merchant_team) as merchant_team')
            ->selectRaw("COALESCE(NULLIF(TRIM(yarn_count), ''), '(Unknown)') as yarn_count")
            ->selectRaw('COALESCE(SUM(order_qty_kg), 0) as order_kg')
            ->selectRaw('COALESCE(SUM(received_qty), 0) as received_kg')
            ->groupBy('merchant_team', 'yarn_count')
            ->get();

        $merchantYarnQtyPivot = $merchantYarnQtyRows->groupBy('merchant_team')
            ->mapWithKeys(fn ($rows, $mt) => [
                $mt => $rows->keyBy('yarn_count')->map(fn ($r) => [
                    'order_kg'    => (float) ($r->order_kg ?? 0),
                    'received_kg' => (float) ($r->received_kg ?? 0),
                ]),
            ]);

        $yarnCountsForMerchantQty = $merchantYarnQtyRows->pluck('yarn_count')->unique()->sort()->values();

        $merchantYarnQtySeries = $yarnCountsForMerchantQty->flatMap(function ($yc) use ($merchantTeams, $merchantYarnQtyPivot) {
            $orderSeries = [
                'name'  => "{$yc} Ordered",
                'group' => 'Ordered',
                'data'  => $merchantTeams->map(fn ($mt) => (float) ($merchantYarnQtyPivot[$mt][$yc]['order_kg'] ?? 0))->values(),
            ];
            $receivedSeries = [
                'name'  => "{$yc} Received",
                'group' => 'Received',
                'data'  => $merchantTeams->map(fn ($mt) => (float) ($merchantYarnQtyPivot[$mt][$yc]['received_kg'] ?? 0))->values(),
            ];
            return [$orderSeries, $receivedSeries];
        })->values();

        $responseData['charts']['merchant_yarn_order_received_breakdown'] = [
            'categories' => $merchantTeams,
            'series'     => $merchantYarnQtySeries,
        ];

        $buildMerchantOrderReceivedBreakdown = function (string $dimensionColumn, string $dimensionAlias, int $takeTop = 999) use ($base, $merchantTeams) {
            $rows = (clone $base)
                ->whereNotNull('merchant_team')->whereRaw("TRIM(merchant_team) != ''")
                ->selectRaw('TRIM(merchant_team) as merchant_team')
                ->selectRaw("COALESCE(NULLIF(TRIM({$dimensionColumn}), ''), '(Unknown)') as {$dimensionAlias}")
                ->selectRaw('COALESCE(SUM(order_qty_kg), 0) as order_kg')
                ->selectRaw('COALESCE(SUM(received_qty), 0) as received_kg')
                ->groupBy('merchant_team', $dimensionAlias)
                ->get();

            $dimValues = $rows->groupBy($dimensionAlias)
                ->map(fn ($group) => (float) $group->sum('order_kg'))
                ->sortByDesc(fn ($v) => $v)
                ->take($takeTop)
                ->keys()
                ->values();

            $pivot = $rows->groupBy('merchant_team')
                ->mapWithKeys(fn ($group, $mt) => [
                    $mt => $group->keyBy($dimensionAlias)->map(fn ($r) => [
                        'order_kg'    => (float) ($r->order_kg ?? 0),
                        'received_kg' => (float) ($r->received_kg ?? 0),
                    ]),
                ]);

            $series = $dimValues->flatMap(function ($dim) use ($merchantTeams, $pivot) {
                $orderSeries = [
                    'name'  => "{$dim} Ordered",
                    'group' => 'Ordered',
                    'data'  => $merchantTeams->map(fn ($mt) => (float) ($pivot[$mt][$dim]['order_kg'] ?? 0))->values(),
                ];
                $receivedSeries = [
                    'name'  => "{$dim} Received",
                    'group' => 'Received',
                    'data'  => $merchantTeams->map(fn ($mt) => (float) ($pivot[$mt][$dim]['received_kg'] ?? 0))->values(),
                ];
                return [$orderSeries, $receivedSeries];
            })->values();

            return [
                'categories' => $merchantTeams,
                'series'     => $series,
            ];
        };

        $responseData['charts']['merchant_type_order_received_breakdown'] = $buildMerchantOrderReceivedBreakdown('UPPER(booking_type)', 'booking_type', 10);
        $responseData['charts']['merchant_fiber_order_received_breakdown'] = $buildMerchantOrderReceivedBreakdown('fiber', 'fiber', 12);

        // Merchant team × yarn_count × type — "Count × Type" combined breakdown
        $yarnTypeRows = (clone $base)
            ->selectRaw("COALESCE(NULLIF(TRIM(merchant_team), ''), '(Unknown)') as merchant_team")
            ->selectRaw("COALESCE(NULLIF(TRIM(yarn_count), ''), '(Unknown)') as yarn_count")
            ->selectRaw("COALESCE(NULLIF(UPPER(TRIM(booking_type)), ''), '(Unknown)') as booking_type")
            ->selectRaw('COALESCE(SUM(order_qty_kg), 0) as order_kg')
            ->selectRaw('COALESCE(SUM(received_qty), 0) as received_kg')
            ->groupBy('merchant_team', 'yarn_count', 'booking_type')
            ->get();

        $topYarnCountsForType = $yarnTypeRows->groupBy('yarn_count')
            ->map(fn ($rows) => $rows->sum('order_kg'))
            ->sortByDesc(fn ($v) => $v)
            ->take(10)
            ->keys()->values();

        $bookingTypesForYarn = $yarnTypeRows->pluck('booking_type')->unique()->sort()->values();

        $yarnTypePivot = $yarnTypeRows->groupBy('merchant_team')
            ->mapWithKeys(fn ($rows, $mt) => [
                $mt => $rows->groupBy('yarn_count')
                    ->mapWithKeys(fn ($ycRows, $yc) => [
                        $yc => $ycRows->keyBy('booking_type')->map(fn ($r) => [
                            'order_kg'    => (float) ($r->order_kg ?? 0),
                            'received_kg' => (float) ($r->received_kg ?? 0),
                        ]),
                    ]),
            ]);

        $yarnTypeSeries = $topYarnCountsForType->flatMap(function ($yc) use ($bookingTypesForYarn, $merchantTeams, $yarnTypePivot) {
            return $bookingTypesForYarn->flatMap(function ($bt) use ($yc, $merchantTeams, $yarnTypePivot) {
                return [
                    [
                        'name'  => "{$yc} / {$bt} Ordered",
                        'group' => 'Ordered',
                        'data'  => $merchantTeams->map(fn ($mt) => (float) ($yarnTypePivot[$mt][$yc][$bt]['order_kg'] ?? 0))->values(),
                    ],
                    [
                        'name'  => "{$yc} / {$bt} Received",
                        'group' => 'Received',
                        'data'  => $merchantTeams->map(fn ($mt) => (float) ($yarnTypePivot[$mt][$yc][$bt]['received_kg'] ?? 0))->values(),
                    ],
                ];
            });
        })->values();

        $responseData['charts']['merchant_yarn_type_order_received_breakdown'] = [
            'categories' => $merchantTeams,
            'series'     => $yarnTypeSeries,
        ];

        // Yarn count × month time series (top 10 counts by KG)
        $yarnTimeRows = (clone $base)
            ->whereNotNull('pi_received_date')
            ->selectRaw("TO_CHAR(pi_received_date, 'YYYY-MM') as ym")
            ->selectRaw("COALESCE(NULLIF(TRIM(yarn_count), ''), '(Unknown)') as yarn_count")
            ->selectRaw('COALESCE(SUM(order_qty_kg), 0) as order_kg')
            ->groupBy('ym', 'yarn_count')
            ->orderBy('ym')
            ->get();

        $topYarnCounts = $yarnTimeRows->groupBy('yarn_count')
            ->map(fn ($rows) => $rows->sum('order_kg'))
            ->sortByDesc(fn ($v) => $v)
            ->take(10)
            ->keys()
            ->values();

        // Only include months that have actual data — no empty gaps
        $dataMonths = $yarnTimeRows->pluck('ym')->unique()->sort()->values();

        $kgPivot = [];
        foreach ($yarnTimeRows as $row) {
            $kgPivot[$row->yarn_count][$row->ym] = (float) $row->order_kg;
        }

        $yarnTimeSeries = $topYarnCounts->map(fn ($yc) => [
            'name' => $yc,
            'data' => $dataMonths->map(fn ($ym) => round($kgPivot[$yc][$ym] ?? 0, 3))->values(),
        ])->values();

        $responseData['charts']['yarn_count_time_series'] = [
            'categories' => $dataMonths->values(),
            'series'     => $yarnTimeSeries,
        ];

        // Fiber × Yarn Count × Booking Type (CF/CC) — horizontal stacked bar per fiber
        $fiberYarnRows = (clone $base)
            ->selectRaw("COALESCE(NULLIF(TRIM(fiber), ''), '(Unknown)') as fiber")
            ->selectRaw("COALESCE(NULLIF(TRIM(yarn_count), ''), '(Unknown)') as yarn_count")
            ->selectRaw("COALESCE(NULLIF(TRIM(booking_type), ''), 'CF') as booking_type")
            ->selectRaw('COALESCE(SUM(order_qty_kg), 0) as order_kg')
            ->groupBy('fiber', 'yarn_count', 'booking_type')
            ->get();

        $responseData['charts']['fiber_yarn_count_stacked'] = [
            'fibers'      => $fiberYarnRows->pluck('fiber')->unique()->sort()->values(),
            'fiber_types' => $fiberYarnRows->pluck('booking_type')->unique()->sort()->values(),
            'rows'        => $fiberYarnRows->map(fn ($r) => [
                'fiber'      => $r->fiber,
                'yarn_count' => $r->yarn_count,
                'type'       => $r->booking_type,
                'kg'         => round((float) $r->order_kg, 3),
            ])->values(),
        ];

        return response()->json($responseData);
    }

    public function analysis(Request $request)
    {
        return Inertia::render('ProcurementFollowup/Analysis', [
            'filters'       => $request->only(['period', 'date_from', 'date_to', 'yarn_count', 'fiber', 'merchant_team', 'booking_type']),
            'filterOptions' => [
                'fiscalYears'   => ProcurementFollowup::distinct()->whereNotNull('fiscal_year')->orderByDesc('fiscal_year')->pluck('fiscal_year')->values(),
                'yarnCounts'    => ProcurementFollowup::distinct()->pluck('yarn_count')->filter()->sort()->values(),
                'fiberTypes'    => ProcurementFollowup::distinct()->pluck('fiber')->filter()->sort()->values(),
                'merchantTeams' => ProcurementFollowup::distinct()->pluck('merchant_team')->filter()->sort()->values(),
            ],
        ]);
    }

    public function analysisData(Request $request)
    {
        // Resolve period → date range
        $period = $request->input('period', '1yr');
        $today  = Carbon::today();
        if ($period === 'fiscal_year' && $request->filled('fiscal_year')) {
            $fy   = (int) $request->input('fiscal_year');
            $from = Carbon::create($fy, 1, 1)->startOfDay();
            $to   = Carbon::create($fy, 12, 31)->endOfDay();
            if ($to->gt(Carbon::today()->endOfDay())) {
                $to = Carbon::today()->endOfDay();
            }
        } elseif ($period === 'custom' && $request->filled('date_from') && $request->filled('date_to')) {
            $from = Carbon::parse($request->date_from)->startOfDay();
            $to   = Carbon::parse($request->date_to)->endOfDay();
        } else {
            $from = match ($period) {
                '4months' => $today->copy()->subMonths(4)->startOfMonth(),
                '6months' => $today->copy()->subMonths(6)->startOfMonth(),
                default   => $today->copy()->subMonths(12)->startOfMonth(),
            };
            $to = $today->copy()->endOfDay();
        }

        $base = $this->buildAnalysisBaseQuery($request, $from, $to);

        $orderByMonth = (clone $base)
            ->whereNotNull('pi_received_date')
            ->selectRaw("TO_CHAR(pi_received_date, 'YYYY-MM') as ym")
            ->selectRaw('COALESCE(SUM(order_qty_kg), 0) as order_kg')
            ->groupBy('ym')
            ->orderBy('ym')
            ->get()
            ->keyBy('ym');

        $receivedByMonth = (clone $base)
            ->whereNotNull('received_date')
            ->whereDate('received_date', '>=', '2000-01-01')
            ->selectRaw("TO_CHAR(received_date, 'YYYY-MM') as ym")
            ->selectRaw('COALESCE(SUM(received_qty), 0) as received_kg')
            ->groupBy('ym')
            ->orderBy('ym')
            ->get()
            ->keyBy('ym');

        $allMonths = $orderByMonth->keys()->merge($receivedByMonth->keys())->unique()->sort()->values();

        $yarnCountWise = (clone $base)
            ->selectRaw("COALESCE(NULLIF(TRIM(yarn_count), ''), '(Unknown)') as yarn_count")
            ->selectRaw('COALESCE(SUM(order_qty_kg), 0) as order_kg')
            ->selectRaw('COALESCE(SUM(received_qty), 0) as received_kg')
            ->groupBy('yarn_count')
            ->orderByDesc('order_kg')
            ->limit(20)
            ->get();

        $merchantTeamWise = (clone $base)
            ->selectRaw("COALESCE(NULLIF(TRIM(merchant_team), ''), '(Unknown)') as merchant_team")
            ->selectRaw('COALESCE(SUM(order_qty_kg), 0) as order_kg')
            ->selectRaw('COALESCE(SUM(received_qty), 0) as received_kg')
            ->groupBy('merchant_team')
            ->orderByDesc('order_kg')
            ->get();

        $fiberDist = (clone $base)
            ->selectRaw("COALESCE(NULLIF(TRIM(fiber), ''), '(Unknown)') as fiber")
            ->selectRaw('COALESCE(SUM(order_qty_kg), 0) as order_kg')
            ->groupBy('fiber')
            ->orderByDesc('order_kg')
            ->limit(12)
            ->get();

        $bookingTypeSplit = (clone $base)
            ->selectRaw("COALESCE(NULLIF(TRIM(booking_type), ''), '(Unknown)') as booking_type")
            ->selectRaw('COUNT(*)::int as cnt')
            ->selectRaw('COALESCE(SUM(order_qty_kg), 0) as order_kg')
            ->groupBy('booking_type')
            ->orderByDesc('order_kg')
            ->get();

        $supplierWise = (clone $base)
            ->selectRaw("COALESCE(NULLIF(TRIM(supplier), ''), '(Unknown)') as supplier")
            ->selectRaw('COALESCE(SUM(order_qty_kg), 0) as order_kg')
            ->selectRaw('COALESCE(SUM(received_qty), 0) as received_kg')
            ->groupBy('supplier')
            ->orderByDesc('order_kg')
            ->limit(20)
            ->get();

        $leadTimeByCount = (clone $base)
            ->whereNotNull('pi_received_date')
            ->whereNotNull('received_date')
            ->selectRaw("COALESCE(NULLIF(TRIM(yarn_count), ''), '(Unknown)') as yarn_count")
            ->selectRaw('ABS(AVG((received_date - pi_received_date))) as avg_days')
            ->selectRaw('COUNT(*)::int as cnt')
            ->groupBy('yarn_count')
            ->orderByDesc('avg_days')
            ->limit(20)
            ->get();

        $metricsRow = (clone $base)
            ->selectRaw('COUNT(*)::int as total_orders')
            ->selectRaw('COALESCE(SUM(order_qty_kg), 0) as total_order_kg')
            ->selectRaw('COALESCE(SUM(received_qty), 0) as total_received_kg')
            ->selectRaw('COALESCE(SUM(GREATEST(balance_qty, 0)), 0) as total_pending_kg')
            ->first();

        $avgLead = (clone $base)
            ->whereNotNull('pi_received_date')
            ->whereNotNull('received_date')
            ->selectRaw('ABS(AVG((received_date - pi_received_date))) as avg_days')
            ->first();

        $onTime = (clone $base)
            ->whereNotNull('eta')
            ->whereNotNull('received_date')
            ->selectRaw('COUNT(*)::int as total')
            ->selectRaw('SUM(CASE WHEN received_date <= eta THEN 1 ELSE 0 END)::int as on_time')
            ->first();

        $onTimeRate = 0.0;
        if (($onTime->total ?? 0) > 0) {
            $onTimeRate = ((float) $onTime->on_time / (float) $onTime->total) * 100.0;
        }

        $today = Carbon::today()->toDateString();
        $aging = (clone $base)
            ->where('balance_qty', '>', 0)
            ->whereNotNull('eta')
            ->selectRaw("
                SUM(CASE WHEN ((?::date - eta)) BETWEEN 0 AND 30 THEN 1 ELSE 0 END)::int as d30,
                SUM(CASE WHEN ((?::date - eta)) BETWEEN 31 AND 60 THEN 1 ELSE 0 END)::int as d60,
                SUM(CASE WHEN ((?::date - eta)) BETWEEN 61 AND 90 THEN 1 ELSE 0 END)::int as d90,
                SUM(CASE WHEN ((?::date - eta)) > 90 THEN 1 ELSE 0 END)::int as d90p
            ", [$today, $today, $today, $today])
            ->first();

        $pendingTotal = (int) ($aging->d30 ?? 0) + (int) ($aging->d60 ?? 0) + (int) ($aging->d90 ?? 0) + (int) ($aging->d90p ?? 0);
        $overdueShare = $pendingTotal > 0 ? ((int) ($aging->d60 ?? 0) + (int) ($aging->d90 ?? 0) + (int) ($aging->d90p ?? 0)) / $pendingTotal : 0.0;
        $reliability = max(0.0, min(100.0, $onTimeRate - ($overdueShare * 30.0)));

        // Available booking types: within period + yarn_count (ignore booking_type, fiber, merchant_team)
        $availableBookingTypes = $this->buildAnalysisBaseQuery($request, $from, $to, ['booking_type', 'fiber', 'merchant_team'])
            ->whereNotNull('booking_type')
            ->whereRaw("TRIM(booking_type) != ''")
            ->selectRaw('UPPER(TRIM(booking_type)) as bt')
            ->groupBy('bt')
            ->orderBy('bt')
            ->pluck('bt')
            ->values();

        // Available fibers: within period + yarn_count + booking_type (ignore fiber, merchant_team)
        $availableFibers = $this->buildAnalysisBaseQuery($request, $from, $to, ['fiber', 'merchant_team'])
            ->whereNotNull('fiber')
            ->whereRaw("TRIM(fiber) != ''")
            ->distinct()
            ->orderBy('fiber')
            ->pluck('fiber')
            ->values();

        // Available merchant teams: within period + all other filters except merchant_team
        $availableMerchantTeams = $this->buildAnalysisBaseQuery($request, $from, $to, ['merchant_team'])
            ->whereNotNull('merchant_team')
            ->whereRaw("TRIM(merchant_team) != ''")
            ->distinct()
            ->orderBy('merchant_team')
            ->pluck('merchant_team')
            ->values();

        return response()->json([
            'availableOptions' => [
                'bookingTypes'  => $availableBookingTypes,
                'fiberTypes'    => $availableFibers,
                'merchantTeams' => $availableMerchantTeams,
            ],
            'charts' => [
                'monthly_trend' => [
                    'categories' => $allMonths,
                    'series' => [
                        ['name' => 'Order KG (PI Date)', 'data' => $allMonths->map(fn ($ym) => (float) ($orderByMonth[$ym]->order_kg ?? 0))->values()],
                        ['name' => 'Received KG (Rcvd Date)', 'data' => $allMonths->map(fn ($ym) => (float) ($receivedByMonth[$ym]->received_kg ?? 0))->values()],
                    ],
                ],
                'yarn_count_wise' => [
                    'categories' => $yarnCountWise->pluck('yarn_count'),
                    'series' => [
                        ['name' => 'Order KG', 'data' => $yarnCountWise->pluck('order_kg')->map(fn ($v) => (float) $v)],
                        ['name' => 'Received KG', 'data' => $yarnCountWise->pluck('received_kg')->map(fn ($v) => (float) $v)],
                    ],
                ],
                'merchant_team_wise' => [
                    'categories' => $merchantTeamWise->pluck('merchant_team'),
                    'series' => [
                        ['name' => 'Order KG', 'data' => $merchantTeamWise->pluck('order_kg')->map(fn ($v) => (float) $v)],
                        ['name' => 'Received KG', 'data' => $merchantTeamWise->pluck('received_kg')->map(fn ($v) => (float) $v)],
                    ],
                ],
                'supplier_wise' => [
                    'categories' => $supplierWise->pluck('supplier'),
                    'series' => [
                        ['name' => 'Order KG', 'data' => $supplierWise->pluck('order_kg')->map(fn ($v) => (float) $v)],
                        ['name' => 'Received KG', 'data' => $supplierWise->pluck('received_kg')->map(fn ($v) => (float) $v)],
                    ],
                ],
                'fiber_distribution' => [
                    'labels' => $fiberDist->pluck('fiber'),
                    'series' => $fiberDist->pluck('order_kg')->map(fn ($v) => (float) $v),
                ],
                'booking_type_split' => [
                    'labels' => $bookingTypeSplit->pluck('booking_type'),
                    'series' => $bookingTypeSplit->pluck('order_kg')->map(fn ($v) => (float) $v),
                ],
                'lead_time_by_count' => [
                    'categories' => $leadTimeByCount->pluck('yarn_count'),
                    'series' => [
                        ['name' => 'Avg Days (PI to Received)', 'data' => $leadTimeByCount->pluck('avg_days')->map(fn ($v) => round((float) $v, 1))],
                    ],
                ],
            ],
            'metrics' => [
                'total_orders' => (int) ($metricsRow->total_orders ?? 0),
                'total_order_kg' => (float) ($metricsRow->total_order_kg ?? 0),
                'total_received_kg' => (float) ($metricsRow->total_received_kg ?? 0),
                'total_pending_kg' => (float) ($metricsRow->total_pending_kg ?? 0),
                'avg_lead_time_days' => isset($avgLead->avg_days) ? round((float) $avgLead->avg_days, 1) : null,
                'on_time_delivery_rate' => round($onTimeRate, 1),
                'supplier_reliability_score' => round($reliability, 1),
                'pending_aging' => [
                    '0_30' => (int) ($aging->d30 ?? 0),
                    '31_60' => (int) ($aging->d60 ?? 0),
                    '61_90' => (int) ($aging->d90 ?? 0),
                    '90p' => (int) ($aging->d90p ?? 0),
                ],
            ],
        ]);
    }

    public function periodicViewRcvDate(Request $request)
    {
        $fiscalYears = ProcurementFollowup::query()
            ->whereNotNull('received_date')
            ->whereDate('received_date', '>=', '2000-01-01')
            ->selectRaw("DISTINCT EXTRACT(YEAR FROM received_date)::int as y")
            ->orderByDesc('y')
            ->pluck('y')
            ->filter()
            ->values();

        return Inertia::render('ProcurementFollowup/PeriodicViewRcvDate', [
            'filters' => $request->only(['period', 'date_from', 'date_to']),
            'filterOptions' => [
                'fiscalYears' => $fiscalYears,
            ],
            'merchantTeamNameMap' => MerchantTeam::get()->flatMap(function ($t) {
                $entries = [];
                if (!empty($t->code))    $entries[$t->code]    = $t->name;
                if (!empty($t->ep_code)) $entries[$t->ep_code] = $t->name;
                return $entries;
            })->toArray(),
        ]);
    }

    public function periodicViewRcvDateData(Request $request)
    {
        if ($request->input('period') === 'fiscal_year' && !$request->filled('fiscal_year')) {
            $earliest = ProcurementFollowup::whereNotNull('received_date')
                ->whereDate('received_date', '>=', '2000-01-01')
                ->min('received_date') ?? '2000-01-01';
            $request->merge([
                'period'    => 'custom',
                'date_from' => $earliest,
                'date_to'   => now()->toDateString(),
            ]);
        }

        $period = $request->input('period', 'fiscal_year');
        $today  = Carbon::today();

        if ($period === 'fiscal_year' && $request->filled('fiscal_year')) {
            $fy   = (int) $request->input('fiscal_year');
            $from = Carbon::create($fy, 1, 1)->startOfDay();
            $to   = Carbon::create($fy, 12, 31)->endOfDay();
            if ($to->gt(Carbon::today()->endOfDay())) {
                $to = Carbon::today()->endOfDay();
            }
        } elseif ($period === 'custom' && $request->filled('date_from') && $request->filled('date_to')) {
            $from = Carbon::parse($request->date_from)->startOfDay();
            $to   = Carbon::parse($request->date_to)->endOfDay();
        } else {
            $from = $today->copy()->subMonths(12)->startOfMonth();
            $to   = $today->copy()->endOfDay();
        }

        $base = ProcurementFollowup::query()
            ->whereNotNull('received_date')
            ->whereDate('received_date', '>=', '2000-01-01')
            ->whereDate('received_date', '>=', $from->toDateString())
            ->whereDate('received_date', '<=', $to->toDateString());

        // Monthly received KG trend
        $receivedByMonth = (clone $base)
            ->selectRaw("TO_CHAR(received_date, 'YYYY-MM') as ym")
            ->selectRaw('COALESCE(SUM(received_qty), 0) as received_kg')
            ->groupBy('ym')
            ->orderBy('ym')
            ->get()
            ->keyBy('ym');

        $allMonths = $receivedByMonth->keys()->sort()->values();

        // Yarn count wise received KG
        $yarnCountWise = (clone $base)
            ->selectRaw("COALESCE(NULLIF(TRIM(yarn_count), ''), '(Unknown)') as yarn_count")
            ->selectRaw('COALESCE(SUM(received_qty), 0) as received_kg')
            ->groupBy('yarn_count')
            ->orderByDesc('received_kg')
            ->limit(20)
            ->get();

        // Merchant team wise received KG
        $merchantTeamWise = (clone $base)
            ->selectRaw("COALESCE(NULLIF(TRIM(merchant_team), ''), '(Unknown)') as merchant_team")
            ->selectRaw('COALESCE(SUM(received_qty), 0) as received_kg')
            ->groupBy('merchant_team')
            ->orderByDesc('received_kg')
            ->get();

        // Fiber distribution of received KG
        $fiberDist = (clone $base)
            ->selectRaw("COALESCE(NULLIF(TRIM(fiber), ''), '(Unknown)') as fiber")
            ->selectRaw('COALESCE(SUM(received_qty), 0) as received_kg')
            ->groupBy('fiber')
            ->orderByDesc('received_kg')
            ->limit(12)
            ->get();

        // Booking type split of received KG
        $bookingTypeSplit = (clone $base)
            ->selectRaw("COALESCE(NULLIF(TRIM(booking_type), ''), '(Unknown)') as booking_type")
            ->selectRaw('COUNT(*)::int as cnt')
            ->selectRaw('COALESCE(SUM(received_qty), 0) as received_kg')
            ->groupBy('booking_type')
            ->orderByDesc('received_kg')
            ->get();

        // Metrics
        $metricsRow = (clone $base)
            ->selectRaw('COUNT(*)::int as total_receipts')
            ->selectRaw('COALESCE(SUM(received_qty), 0) as total_received_kg')
            ->first();

        // Yarn count × month time series (top 10 by received KG)
        $yarnTimeRows = (clone $base)
            ->selectRaw("TO_CHAR(received_date, 'YYYY-MM') as ym")
            ->selectRaw("COALESCE(NULLIF(TRIM(yarn_count), ''), '(Unknown)') as yarn_count")
            ->selectRaw('COALESCE(SUM(received_qty), 0) as received_kg')
            ->groupBy('ym', 'yarn_count')
            ->orderBy('ym')
            ->get();

        $topYarnCounts = $yarnTimeRows->groupBy('yarn_count')
            ->map(fn ($rows) => $rows->sum('received_kg'))
            ->sortByDesc(fn ($v) => $v)
            ->take(10)
            ->keys()
            ->values();

        $dataMonths = $yarnTimeRows->pluck('ym')->unique()->sort()->values();

        $kgPivot = [];
        foreach ($yarnTimeRows as $row) {
            $kgPivot[$row->yarn_count][$row->ym] = (float) $row->received_kg;
        }

        $yarnTimeSeries = $topYarnCounts->map(fn ($yc) => [
            'name' => $yc,
            'data' => $dataMonths->map(fn ($ym) => round($kgPivot[$yc][$ym] ?? 0, 3))->values(),
        ])->values();

        // Merchant team × yarn count stacked (received KG)
        $merchantYarnRows = (clone $base)
            ->whereNotNull('merchant_team')->whereRaw("TRIM(merchant_team) != ''")
            ->selectRaw('TRIM(merchant_team) as merchant_team')
            ->selectRaw("COALESCE(NULLIF(TRIM(yarn_count), ''), '(Unknown)') as yarn_count")
            ->selectRaw('COALESCE(SUM(received_qty), 0) as received_kg')
            ->groupBy('merchant_team', 'yarn_count')
            ->get();

        $merchantTeams = $merchantYarnRows->groupBy('merchant_team')
            ->map(fn ($rows) => $rows->sum('received_kg'))
            ->sortByDesc(fn ($v) => $v)
            ->keys()->values();

        $yarnCountsForMerchant = $merchantYarnRows->pluck('yarn_count')->unique()->sort()->values();

        $merchantYarnPivot = $merchantYarnRows->groupBy('merchant_team')
            ->mapWithKeys(fn ($rows, $mt) => [$mt => $rows->pluck('received_kg', 'yarn_count')]);

        $merchantYarnSeries = $yarnCountsForMerchant->map(fn ($yc) => [
            'name' => $yc,
            'data' => $merchantTeams->map(fn ($mt) => (float) ($merchantYarnPivot[$mt][$yc] ?? 0))->values(),
        ])->values();

        // Yarn count × booking type breakdown (received KG)
        $breakdown = (clone $base)
            ->selectRaw("COALESCE(NULLIF(TRIM(yarn_count), ''), '(Unknown)') as yarn_count")
            ->selectRaw("COALESCE(NULLIF(UPPER(TRIM(booking_type)), ''), '(Unknown)') as booking_type")
            ->selectRaw('COALESCE(SUM(received_qty), 0) as received_kg')
            ->groupBy('yarn_count', 'booking_type')
            ->get();

        $yarnTotals = $breakdown->groupBy('yarn_count')
            ->map(fn ($rows) => $rows->sum('received_kg'))
            ->sortByDesc(fn ($v) => $v)
            ->take(20)
            ->keys()
            ->values();

        $bookingTypes = $breakdown->pluck('booking_type')->unique()->sort()->values();
        $pivot = $breakdown->groupBy('yarn_count')
            ->mapWithKeys(fn ($rows, $yc) => [$yc => $rows->pluck('received_kg', 'booking_type')]);

        $yarnCountTypeSeries = $bookingTypes->map(fn ($bt) => [
            'name' => $bt,
            'data' => $yarnTotals->map(fn ($yc) => (float) ($pivot[$yc][$bt] ?? 0))->values(),
        ])->values();

        return response()->json([
            'charts' => [
                'monthly_trend' => [
                    'categories' => $allMonths,
                    'series' => [
                        ['name' => 'Received KG', 'data' => $allMonths->map(fn ($ym) => (float) ($receivedByMonth[$ym]->received_kg ?? 0))->values()],
                    ],
                ],
                'yarn_count_wise' => [
                    'categories' => $yarnCountWise->pluck('yarn_count'),
                    'series' => [
                        ['name' => 'Received KG', 'data' => $yarnCountWise->pluck('received_kg')->map(fn ($v) => (float) $v)],
                    ],
                ],
                'merchant_team_wise' => [
                    'categories' => $merchantTeamWise->pluck('merchant_team'),
                    'series' => [
                        ['name' => 'Received KG', 'data' => $merchantTeamWise->pluck('received_kg')->map(fn ($v) => (float) $v)],
                    ],
                ],
                'fiber_distribution' => [
                    'labels' => $fiberDist->pluck('fiber'),
                    'series' => $fiberDist->pluck('received_kg')->map(fn ($v) => (float) $v),
                ],
                'booking_type_split' => [
                    'labels' => $bookingTypeSplit->pluck('booking_type'),
                    'series' => $bookingTypeSplit->pluck('received_kg')->map(fn ($v) => (float) $v),
                ],
                'yarn_count_time_series' => [
                    'categories' => $dataMonths->values(),
                    'series'     => $yarnTimeSeries,
                ],
                'merchant_yarn_breakdown' => [
                    'categories' => $merchantTeams,
                    'series'     => $merchantYarnSeries,
                ],
                'yarn_count_type_breakdown' => [
                    'categories' => $yarnTotals,
                    'series'     => $yarnCountTypeSeries,
                ],
            ],
            'metrics' => [
                'total_receipts'    => (int) ($metricsRow->total_receipts ?? 0),
                'total_received_kg' => (float) ($metricsRow->total_received_kg ?? 0),
            ],
        ]);
    }

    public function supplierOrderBreakdown(Request $request)
    {
        $rows = ProcurementFollowup::query()
            ->selectRaw("COALESCE(NULLIF(TRIM(supplier), ''), '(Unknown)') as supplier")
            ->selectRaw('COALESCE(SUM(order_qty_kg), 0) as ordered_kg')
            ->selectRaw('COALESCE(SUM(received_qty), 0) as received_kg')
            ->selectRaw('COUNT(*)::int as pi_count')
            ->selectRaw('COUNT(*) FILTER (WHERE eta IS NOT NULL AND received_date IS NOT NULL)::int as leadtime_count')
            ->selectRaw('SUM(CASE WHEN eta IS NOT NULL AND received_date IS NOT NULL AND received_date <= eta THEN 1 ELSE 0 END)::int as on_time_count')
            ->selectRaw('AVG(CASE WHEN eta IS NOT NULL AND received_date IS NOT NULL THEN (received_date - eta) ELSE NULL END) as avg_delay_days')
            ->groupBy('supplier')
            ->orderByDesc('ordered_kg')
            ->get();

        $breakdown = $rows->map(function ($r) {
            $ordered = (float) ($r->ordered_kg ?? 0);
            $received = (float) ($r->received_kg ?? 0);
            $balance = max(0.0, $ordered - $received);

            $fulfilment = 0.0;
            if ($ordered > 0) {
                $fulfilment = min(100.0, max(0.0, ($received / $ordered) * 100.0));
            }

            $status = 'Pending';
            if ($balance <= 0.000001) $status = 'Completed';
            elseif ($received > 0) $status = 'Partial';

            $leadCount = (int) ($r->leadtime_count ?? 0);
            $onTimeCount = (int) ($r->on_time_count ?? 0);
            $avgDelay = isset($r->avg_delay_days) ? (float) $r->avg_delay_days : 0.0;
            $onTimeRate = $leadCount > 0 ? ((float) $onTimeCount / (float) $leadCount) * 100.0 : null;
            $rating = $onTimeRate === null ? null : $this->supplierRating($onTimeRate, $avgDelay);

            return [
                'supplier' => $r->supplier,
                'ordered_kg' => round($ordered, 3),
                'received_kg' => round($received, 3),
                'balance_kg' => round($balance, 3),
                'fulfilment_pct' => round($fulfilment, 1),
                'status' => $status,
                'review' => [
                    'rating' => $rating,
                    'on_time_rate' => $onTimeRate === null ? null : round($onTimeRate, 1),
                    'avg_delay_days' => round($avgDelay, 1),
                    'leadtime_count' => $leadCount,
                ],
            ];
        });

        return Inertia::render('ProcurementFollowup/SupplierOrderBreakdown', [
            'breakdown' => $breakdown,
        ]);
    }
}
