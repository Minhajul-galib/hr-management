<?php
namespace App\Http\Controllers;

use App\Imports\YarnSuppliersImport;
use App\Models\YarnSupplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

class YarnSupplierController extends Controller
{
    public function index()
    {
        $showTrashed = request()->boolean('trash');
        $suppliers = $showTrashed
            ? YarnSupplier::onlyTrashed()->latest()->get()
            : YarnSupplier::latest()->get();

        return Inertia::render('YarnSuppliers/Index', [
            'suppliers' => $suppliers,
            'showTrashed' => $showTrashed,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:255',
            'alphabet'    => 'nullable|string|max:5',
            'code'        => 'nullable|string|max:50|unique:yarn_suppliers,code',
            'country'     => 'nullable|string|max:100',
            'excel_input' => 'nullable|string|max:255',
            'contact'     => 'nullable|string|max:255',
        ]);
        $validated['created_by'] = Auth::id();
        YarnSupplier::create($validated);
        return back()->with('success', 'Supplier created successfully.');
    }

    public function update(Request $request, YarnSupplier $yarnSupplier)
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:255',
            'alphabet'    => 'nullable|string|max:5',
            'code'        => 'nullable|string|max:50|unique:yarn_suppliers,code,' . $yarnSupplier->getKey(),
            'country'     => 'nullable|string|max:100',
            'excel_input' => 'nullable|string|max:255',
            'contact'     => 'nullable|string|max:255',
            'is_active'   => 'boolean',
        ]);
        $yarnSupplier->update($validated);
        return back()->with('success', 'Supplier updated successfully.');
    }

    public function destroy(YarnSupplier $yarnSupplier)
    {
        $yarnSupplier->delete();
        return back()->with('success', 'Supplier moved to Trash.');
    }

    public function restore(int $id)
    {
        $supplier = YarnSupplier::withTrashed()->findOrFail($id);
        $supplier->restore();

        return back()->with('success', 'Supplier restored successfully.');
    }

    public function forceDestroy(int $id)
    {
        $supplier = YarnSupplier::withTrashed()->findOrFail($id);
        $supplier->forceDelete();

        return back()->with('success', 'Supplier deleted permanently.');
    }

    public function bulkDestroy(Request $request)
    {
        $ids = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ])['ids'];

        YarnSupplier::whereIn('id', $ids)->delete();

        return back()->with('success', count($ids) . ' suppliers moved to Trash.');
    }

    public function bulkRestore(Request $request)
    {
        $ids = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ])['ids'];

        YarnSupplier::onlyTrashed()->whereIn('id', $ids)->restore();

        return back()->with('success', count($ids) . ' suppliers restored.');
    }

    public function bulkForceDestroy(Request $request)
    {
        $ids = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ])['ids'];

        YarnSupplier::withTrashed()->whereIn('id', $ids)->forceDelete();

        return back()->with('success', count($ids) . ' suppliers deleted permanently.');
    }

    public function importPage()
    {
        return Inertia::render('YarnSuppliers/Import');
    }

    public function importConfirm(Request $request): \Illuminate\Http\JsonResponse
    {
        $rows = $request->input('rows', []);
        $imported = 0;
        $skipped = 0;
        $rowErrors = [];

        foreach ($rows as $i => $row) {
            $name = trim($row['name'] ?? '');
            if (!$name) {
                $skipped++;
                continue;
            }
            try {
                YarnSupplier::create([
                    'name'        => $name,
                    'alphabet'    => trim($row['alphabet'] ?? '') ?: null,
                    'country'     => trim($row['country'] ?? '') ?: null,
                    'excel_input' => trim($row['excel_input'] ?? '') ?: null,
                    'code'        => trim($row['code'] ?? '') ?: null,
                    'contact'     => trim($row['contact'] ?? '') ?: null,
                    'is_active'   => true,
                    'created_by'  => Auth::id(),
                ]);
                $imported++;
            } catch (\Exception $e) {
                $rowErrors[] = [
                    'row'   => $row['row'] ?? ($i + 2),
                    'name'  => $name,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'imported' => $imported,
            'skipped'  => $skipped,
            'errors'   => $rowErrors,
        ]);
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
                'alphabet'      => 'alphabet',
                'supplier name' => 'name',
                'supplier_name' => 'name',
                'name'          => 'name',
                'origin'        => 'country',
                'country'       => 'country',
                'excel input'   => 'excel_input',
                'excel_input'   => 'excel_input',
                'supplier_code' => 'code',
                'supplier code' => 'code',
                'code'          => 'code',
                'contact'       => 'contact',
            ];

            $colMap = [];
            foreach ($rawHeaders as $idx => $h) {
                if (isset($headerMap[$h])) {
                    $colMap[$idx] = $headerMap[$h];
                }
            }

            $preview = [];
            foreach (array_slice($allRows, 1) as $i => $row) {
                $entry = ['row' => $i + 2, 'alphabet' => null, 'name' => null, 'country' => null, 'excel_input' => null, 'code' => null, 'contact' => null];
                foreach ($colMap as $idx => $field) {
                    $val = $row[$idx] ?? null;
                    $entry[$field] = ($val !== null && $val !== '') ? (string) $val : null;
                }
                if (!array_filter(array_slice($entry, 1))) continue;
                $preview[] = $entry;
            }

            return response()->json(['rows' => $preview, 'total' => count($preview)]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimetypes:text/csv,text/plain,application/csv,text/comma-separated-values,application/excel,application/vnd.ms-excel,application/vnd.msexcel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet|max:2048',
        ]);

        try {
            Excel::import(new YarnSuppliersImport, $request->file('file'));
            return back()->with('success', 'Yarn suppliers imported successfully.');
        } catch (\Exception $e) {
            return back()->with('error', 'Import failed: ' . $e->getMessage());
        }
    }

    public function downloadTemplate()
    {
        $headers = ['Alphabet', 'Supplier Name', 'Origin', 'Excel Input', 'Supplier_code', 'Contact'];

        $sampleRows = [
            ['A', 'Acme Yarns Ltd', 'China', 'Acme Yarns', 'SUP001', '+86-123-456-7890'],
            ['B', 'Bengal Spinning Mills', 'Bangladesh', 'Bengal Spinning', 'SUP002', ''],
        ];

        $filename = 'yarn_supplier_import_template.csv';

        $callback = function () use ($headers, $sampleRows) {
            $out = fopen('php://output', 'w');
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
