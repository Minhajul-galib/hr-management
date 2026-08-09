<?php
namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\MerchantTeam;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class MerchantTeamController extends Controller
{
    public function index()
    {
        $showTrashed = request()->boolean('trash');
        $teams = $showTrashed
            ? MerchantTeam::onlyTrashed()->with(['head', 'department'])->latest()->get()
            : MerchantTeam::with(['head', 'department'])->latest()->get();
        $users = User::select('id', 'name')->orderBy('name')->get();
        $departments = Department::select('id', 'name')->orderBy('name')->get();
        return Inertia::render('MerchantTeams/Index', [
            'teams'       => $teams,
            'users'       => $users,
            'departments' => $departments,
            'showTrashed' => $showTrashed,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'             => 'required|string|max:255',
            'code'             => 'nullable|string|max:50',
            'ep_code'          => 'nullable|string|max:50',
            'head_name'        => 'nullable|string|max:255',
            'head_designation' => 'nullable|string|max:255',
            'head_user_id'     => 'nullable|exists:users,id',
            'department_id'    => 'nullable|exists:departments,id',
        ]);
        $validated['created_by'] = Auth::id();
        MerchantTeam::create($validated);
        return back()->with('success', 'Merchandiser created successfully.');
    }

    public function update(Request $request, MerchantTeam $merchantTeam)
    {
        $validated = $request->validate([
            'name'             => 'required|string|max:255',
            'code'             => 'nullable|string|max:50',
            'ep_code'          => 'nullable|string|max:50',
            'head_name'        => 'nullable|string|max:255',
            'head_designation' => 'nullable|string|max:255',
            'head_user_id'     => 'nullable|exists:users,id',
            'department_id'    => 'nullable|exists:departments,id',
            'is_active'        => 'boolean',
        ]);
        $merchantTeam->update($validated);
        return back()->with('success', 'Merchandiser updated successfully.');
    }

    public function destroy(MerchantTeam $merchantTeam)
    {
        $merchantTeam->delete();
        return back()->with('success', 'Merchandiser moved to Trash.');
    }

    public function restore(int $id)
    {
        $team = MerchantTeam::withTrashed()->findOrFail($id);
        $team->restore();

        return back()->with('success', 'Merchandiser restored successfully.');
    }

    public function forceDestroy(int $id)
    {
        $team = MerchantTeam::withTrashed()->findOrFail($id);
        $team->forceDelete();

        return back()->with('success', 'Merchandiser deleted permanently.');
    }

    public function bulkDestroy(Request $request)
    {
        $ids = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ])['ids'];

        MerchantTeam::whereIn('id', $ids)->delete();

        return back()->with('success', count($ids) . ' merchandisers moved to Trash.');
    }

    public function bulkRestore(Request $request)
    {
        $ids = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ])['ids'];

        MerchantTeam::onlyTrashed()->whereIn('id', $ids)->restore();

        return back()->with('success', count($ids) . ' merchandisers restored.');
    }

    public function bulkForceDestroy(Request $request)
    {
        $ids = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ])['ids'];

        MerchantTeam::withTrashed()->whereIn('id', $ids)->forceDelete();

        return back()->with('success', count($ids) . ' merchandisers deleted permanently.');
    }

    // ── Import ────────────────────────────────────────────────────────────────

    public function importPage()
    {
        return Inertia::render('MerchantTeams/Import', [
            'departments' => Department::select('id', 'name')->orderBy('name')->get(),
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
                'ep code'          => 'ep_code',
                'ep_code'          => 'ep_code',
                'employee code'    => 'ep_code',
                'name'             => 'head_name',
                'head name'        => 'head_name',
                'head_name'        => 'head_name',
                'department'       => 'department',
                'designation'      => 'head_designation',
                'head designation' => 'head_designation',
                'head_designation' => 'head_designation',
                'team name'        => 'team_name',
                'team_name'        => 'team_name',
                'team code'        => 'team_code',
                'team_code'        => 'team_code',
                'code'             => 'team_code',
            ];

            $colMap = [];
            foreach ($rawHeaders as $idx => $h) {
                if (isset($headerMap[$h])) {
                    $colMap[$idx] = $headerMap[$h];
                }
            }

            $preview = [];
            foreach (array_slice($allRows, 1) as $i => $row) {
                $entry = [
                    'row'              => $i + 2,
                    'ep_code'          => null,
                    'head_name'        => null,
                    'department'       => null,
                    'head_designation' => null,
                    'team_name'        => null,
                    'team_code'        => null,
                ];
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

    public function importConfirm(Request $request): \Illuminate\Http\JsonResponse
    {
        $rows = $request->input('rows', []);
        $imported = 0;
        $skipped  = 0;
        $rowErrors = [];

        // Cache departments by lowercase name for fast lookup
        $deptCache = Department::pluck('id', 'name')->mapWithKeys(
            fn($id, $name) => [strtolower($name) => $id]
        )->toArray();

        foreach ($rows as $i => $row) {
            $teamName = trim($row['team_name'] ?? '');
            if (!$teamName) {
                $skipped++;
                continue;
            }

            try {
                // Resolve department
                $deptName = trim($row['department'] ?? '');
                $deptId   = null;
                if ($deptName) {
                    $deptId = $deptCache[strtolower($deptName)] ?? null;
                    if (!$deptId) {
                        $dept   = Department::create(['name' => $deptName]);
                        $deptId = $dept->id;
                        $deptCache[strtolower($deptName)] = $deptId;
                    }
                }

                // Find existing record only by ep_code; otherwise always create new
                $epCode = trim($row['ep_code'] ?? '') ?: null;
                $team   = null;
                if ($epCode) {
                    $team = MerchantTeam::where('ep_code', $epCode)->first();
                }

                $data = [
                    'name'             => $teamName,
                    'code'             => trim($row['team_code'] ?? '') ?: null,
                    'ep_code'          => $epCode,
                    'head_name'        => trim($row['head_name'] ?? '') ?: null,
                    'head_designation' => trim($row['head_designation'] ?? '') ?: null,
                    'department_id'    => $deptId,
                    'is_active'        => true,
                ];

                if ($team) {
                    $team->update($data);
                } else {
                    $data['created_by'] = Auth::id();
                    MerchantTeam::create($data);
                }

                $imported++;
            } catch (\Exception $e) {
                $rowErrors[] = [
                    'row'       => $row['row'] ?? ($i + 2),
                    'team_name' => $teamName,
                    'error'     => $e->getMessage(),
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
        $headers = ['EP Code', 'Name', 'Department', 'Designation', 'Team Name', 'Team Code'];
        $sample  = [
            ['17983', 'Ashutosh Biswas',           'Merchandizing', 'Director - Merchandizing', 'Ashu Team',  '2026-001AS'],
            ['13752', 'Mrs. Lepea Nasrin',          'Merchandizing', 'GM',                       'Lepea Team', '2026-001LE'],
            ['16348', 'Mirja Mohd. Abu Hossain',   'Merchandizing', 'DGM',                      'Mirja Team', '2026-005NM'],
        ];

        return response()->streamDownload(function () use ($headers, $sample) {
            $out = fopen('php://output', 'w');
            fwrite($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($out, $headers);
            foreach ($sample as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, 'merchant_team_import_template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
