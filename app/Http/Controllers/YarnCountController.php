<?php
namespace App\Http\Controllers;

use App\Models\YarnCount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class YarnCountController extends Controller
{
    public function index()
    {
        $showTrashed = request()->boolean('trash');
        $yarnCounts = $showTrashed
            ? YarnCount::onlyTrashed()->latest()->get()
            : YarnCount::latest()->get();
        return Inertia::render('YarnCounts/Index', [
            'yarnCounts' => $yarnCounts,
            'showTrashed' => $showTrashed,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'count_value' => 'required|string|max:50',
            'code'        => 'nullable|string|max:50',
        ]);
        $validated['created_by'] = Auth::id();
        YarnCount::create($validated);
        return back()->with('success', 'Yarn Count created successfully.');
    }

    public function update(Request $request, YarnCount $yarnCount)
    {
        $validated = $request->validate([
            'count_value' => 'required|string|max:50',
            'code'        => 'nullable|string|max:50',
            'is_active'   => 'boolean',
        ]);
        $yarnCount->update($validated);
        return back()->with('success', 'Yarn Count updated successfully.');
    }

    public function destroy(YarnCount $yarnCount)
    {
        $yarnCount->delete();
        return back()->with('success', 'Yarn Count moved to Trash.');
    }

    public function restore(int $id)
    {
        $row = YarnCount::withTrashed()->findOrFail($id);
        $row->restore();

        return back()->with('success', 'Yarn Count restored successfully.');
    }

    public function forceDestroy(int $id)
    {
        $row = YarnCount::withTrashed()->findOrFail($id);
        $row->forceDelete();

        return back()->with('success', 'Yarn Count deleted permanently.');
    }

    public function bulkDestroy(Request $request)
    {
        $ids = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ])['ids'];

        YarnCount::whereIn('id', $ids)->delete();

        return back()->with('success', count($ids) . ' yarn counts moved to Trash.');
    }

    public function bulkRestore(Request $request)
    {
        $ids = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ])['ids'];

        YarnCount::onlyTrashed()->whereIn('id', $ids)->restore();

        return back()->with('success', count($ids) . ' yarn counts restored.');
    }

    public function bulkForceDestroy(Request $request)
    {
        $ids = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ])['ids'];

        YarnCount::withTrashed()->whereIn('id', $ids)->forceDelete();

        return back()->with('success', count($ids) . ' yarn counts deleted permanently.');
    }

    // ── Import ────────────────────────────────────────────────────────────────

    public function importPage()
    {
        return Inertia::render('YarnCounts/Import');
    }

    public function importPreview(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->validate(['file' => 'required|file|max:5120']);

        try {
            $path = $request->file('file')->getRealPath();
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
            $allRows = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);

            if (empty($allRows)) {
                return response()->json(['rows' => [], 'total' => 0]);
            }

            $rawHeaders = array_map(fn($h) => strtolower(trim((string) $h)), $allRows[0]);

            $headerMap = [
                'count value'  => 'count_value',
                'count_value'  => 'count_value',
                'count'        => 'count_value',
                'yarn count'   => 'count_value',
                'yarn_count'   => 'count_value',
                'value'        => 'count_value',
                'name'         => 'count_value',
                'code'         => 'code',
            ];

            $colMap = [];
            foreach ($rawHeaders as $idx => $h) {
                if (isset($headerMap[$h])) {
                    $colMap[$idx] = $headerMap[$h];
                }
            }

            // If no header matched, treat first column as count_value
            if (empty($colMap)) {
                $colMap[0] = 'count_value';
            }

            $preview = [];
            foreach (array_slice($allRows, 1) as $i => $row) {
                $entry = ['row' => $i + 2, 'count_value' => null, 'code' => null];
                foreach ($colMap as $idx => $field) {
                    $val = $row[$idx] ?? null;
                    $entry[$field] = ($val !== null && $val !== '') ? (string) $val : null;
                }
                if (!$entry['count_value']) continue;
                $preview[] = $entry;
            }

            return response()->json(['rows' => $preview, 'total' => count($preview)]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function importConfirm(Request $request): \Illuminate\Http\JsonResponse
    {
        $rows = $request->input('rows', []);
        $imported = 0;
        $skipped  = 0;
        $rowErrors = [];

        // Cache existing values to detect duplicates
        $existing = YarnCount::withTrashed()->pluck('id', 'count_value')
            ->mapWithKeys(fn($id, $val) => [strtolower($val) => $id])
            ->toArray();

        foreach ($rows as $i => $row) {
            $value = trim($row['count_value'] ?? '');
            if (!$value) {
                $skipped++;
                continue;
            }
            // Skip exact duplicates silently
            if (isset($existing[strtolower($value)])) {
                $skipped++;
                continue;
            }
            try {
                YarnCount::create([
                    'count_value' => $value,
                    'code'        => trim($row['code'] ?? '') ?: null,
                    'is_active'   => true,
                    'created_by'  => Auth::id(),
                ]);
                $existing[strtolower($value)] = true;
                $imported++;
            } catch (\Exception $e) {
                $rowErrors[] = [
                    'row'         => $row['row'] ?? ($i + 2),
                    'count_value' => $value,
                    'error'       => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'imported' => $imported,
            'skipped'  => $skipped,
            'errors'   => $rowErrors,
        ]);
    }

    public function downloadTemplate(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fwrite($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($out, ['Count Value', 'Code']);
            foreach ([['10s', '10S'], ['20s', '20S'], ['30s', '30S'], ['40s', '40S'], ['50s', '50S'], ['60s', '60S']] as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, 'yarn_count_import_template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
