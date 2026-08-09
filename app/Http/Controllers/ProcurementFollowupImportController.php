<?php

namespace App\Http\Controllers;

use App\Imports\ProcurementFollowupsUpsertImport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\HeadingRowImport;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class ProcurementFollowupImportController extends Controller
{
    private function canonicalColumns(): array
    {
        return [
            'supplier',
            'merchandiser',
            'merchant_team',
            'yarn_count',
            'booking_type',
            'fiber_description',
            'fiber',
            'order_qty_kg',
            'pi_number',
            'pi_received_date',
            'lc_number',
            'lc_issue_date',
            'lc_factory',
            'commercial_invoice',
            'bl_qty_ctn',
            'org_document_date',
            'ex_factory_date',
            'etd',
            'eta',
            'received_qty',
            'received_date',
            'remark',
        ];
    }

    private function headerAliases(): array
    {
        // canonical => aliases
        return [
            'supplier' => ['supplier', 'supplier_name', 'party', 'mill', 'supplier name'],
            'merchandiser' => ['merchandiser', 'merch', 'mdsr'],
            'merchant_team' => ['merchant_team', 'merchant team', 'team', 'merchant'],
            'yarn_count' => ['yarn_count', 'yarn count', 'count', 'count_value', 'yarn'],
            'booking_type' => ['booking_type', 'booking type', 'type', 'cf/cc', 'cf_cc', 'cf', 'cc'],
            'fiber_description' => ['fiber_description', 'fiber description', 'fiber desc', 'composition'],
            'fiber' => ['fiber', 'fiber_type', 'fiber type'],
            'order_qty_kg' => ['order_qty_kg', 'order qty', 'order_qty', 'order qty (kg)', 'order_kg', 'qty_kg', 'order quantity'],
            'pi_number' => ['pi_number', 'pi', 'pi no', 'pi_no', 'pi#', 'pi number'],
            'pi_received_date' => ['pi_received_date', 'pi received date', 'pi_rcvd_date', 'pi rcvd', 'pi date'],
            'lc_number' => ['lc_number', 'lc', 'lc no', 'lc_no', 'lc#', 'lc number'],
            'lc_issue_date' => ['lc_issue_date', 'lc issue date', 'lc_date', 'lc issued'],
            'lc_factory' => ['lc_factory', 'lc factory', 'factory', 'opening bank', 'bank'],
            'commercial_invoice' => ['commercial_invoice', 'commercial invoice', 'ci', 'c. invoice', 'invoice', 'invoice no'],
            'bl_qty_ctn' => ['bl_qty_ctn', 'bl qty', 'bl qty ctn', 'bl_qty', 'bl ctn', 'bl qty/ctn'],
            'org_document_date' => ['org_document_date', 'org doc date', 'org document', 'org doc', 'org doc. date'],
            'ex_factory_date' => ['ex_factory_date', 'ex factory date', 'ex-factory', 'ex_factory', 'ex fty date'],
            'etd' => ['etd', 'estimated time of departure'],
            'eta' => ['eta', 'estimated time of arrival'],
            'received_qty' => ['received_qty', 'received qty', 'rcvd qty', 'received', 'received_kg'],
            'received_date' => ['received_date', 'received date', 'rcvd date'],
            'remark' => ['remark', 'remarks', 'status', 'comment', 'note'],
        ];
    }

    private function normalizeHeader(string $h): string
    {
        $h = trim(mb_strtolower($h));
        $h = preg_replace('/[\s\-\/]+/u', '_', $h);
        $h = preg_replace('/[^a-z0-9_]+/u', '', $h);
        return $h ?? '';
    }

    private function detectMapping(array $headers): array
    {
        $aliases = $this->headerAliases();
        $normalizedToOriginal = [];
        foreach ($headers as $h) {
            $normalizedToOriginal[$this->normalizeHeader((string) $h)] = (string) $h;
        }

        $mapping = [];
        foreach ($this->canonicalColumns() as $col) {
            $mapping[$col] = null;
            foreach ($aliases[$col] ?? [] as $alias) {
                $key = $this->normalizeHeader($alias);
                if (isset($normalizedToOriginal[$key])) {
                    $mapping[$col] = $normalizedToOriginal[$key];
                    break;
                }
            }
        }
        return $mapping;
    }

    public function page()
    {
        return Inertia::render('ProcurementFollowup/Import', [
            'canonicalColumns' => $this->canonicalColumns(),
        ]);
    }

    public function downloadTemplate()
    {
        $headers = $this->canonicalColumns();

        $sampleRows = [
            [
                'ABC Yarn Ltd.',
                'John Doe',
                'Team A',
                '50S/1',
                'CF',
                '100% Cotton Combed',
                '100% Ctn Cmb',
                '25000',
                'PI-2024-0001',
                '2024-07-15',
                'LC-0001',
                '2024-07-20',
                'CTAL',
                'CI-0001',
                '1200',
                '2024-07-25',
                '2024-08-05',
                '2024-08-10',
                '2024-08-25',
                '5000',
                '2024-08-26',
                'Pending',
            ],
        ];

        $filename = 'procurement_followup_import_template.csv';

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

    /** Mirror of ProcurementFollowupsUpsertImport::parseNumber for preview validation. */
    private function parseNumber(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        if (is_float($value) || is_int($value)) return (string) $value;
        $clean = str_replace([' ', ',', "'"], '', trim((string) $value));
        return is_numeric($clean) ? $clean : null;
    }

    /** Mirror of ProcurementFollowupsUpsertImport::parseDate for preview validation. */
    private function parseDate(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;

        // Excel numeric serial date (e.g. 45678)
        if (is_numeric($value) && (int) $value > 1000 && (int) $value < 100000) {
            try {
                return \Carbon\Carbon::createFromFormat('Y-m-d', '1899-12-30')
                    ->addDays((int) $value)
                    ->format('Y-m-d');
            } catch (\Throwable) {}
        }

        $str = trim((string) $value);
        if ($str === '') return null;

        // Try formats most common in BD/garment industry spreadsheets
        $formats = [
            'd.m.y',    // 05.12.24
            'd.m.Y',    // 05.12.2024
            'd/m/y',    // 05/12/24
            'd/m/Y',    // 05/12/2024
            'd-m-y',    // 05-12-24
            'd-m-Y',    // 05-12-2024
            'Y-m-d',    // 2024-12-05
            'm/d/Y',    // 12/05/2024 (Excel default)
            'd M Y',    // 05 Dec 2024
        ];

        foreach ($formats as $fmt) {
            try {
                return \Carbon\Carbon::createFromFormat($fmt, $str)->format('Y-m-d');
            } catch (\Throwable) {}
        }

        // Last resort: Carbon's flexible parse
        try {
            return \Carbon\Carbon::parse($str)->format('Y-m-d');
        } catch (\Throwable) {}

        return null;
    }

    public function preview(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimetypes:text/csv,text/plain,application/csv,text/comma-separated-values,application/excel,application/vnd.ms-excel,application/vnd.msexcel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet|max:10240',
        ]);

        $importId = (string) Str::uuid();
        $path = $request->file('file')->storeAs("imports/procurement-followup/{$importId}", $request->file('file')->getClientOriginalName(), 'local');

        $headings = (new HeadingRowImport)->toArray($request->file('file'));
        $headers = array_values(array_filter(($headings[0][0] ?? []), fn ($h) => $h !== null && $h !== ''));

        $mapping = (array) $request->input('mapping', []);
        $suggestedMapping = $this->detectMapping($headers);
        $effectiveMapping = array_merge($suggestedMapping, array_filter($mapping, fn ($v) => $v !== null && $v !== ''));

        // Read ALL rows for full preview — always from sheet index 0 (first sheet).
        // Without WithMultipleSheets the importer uses the Excel "active" sheet,
        // which may differ from sheet 0 when the file has auxiliary sheets (e.g. _review).
        $capture = new class implements ToArray, WithHeadingRow, \Maatwebsite\Excel\Concerns\WithMultipleSheets {
            public array $rows = [];
            public function array(array $array): void
            {
                $this->rows = $array;
            }
            public function sheets(): array
            {
                return [0 => $this];
            }
        };
        Excel::import($capture, $request->file('file'));

        $numericCols = ['order_qty_kg', 'received_qty', 'bl_qty_ctn'];
        $totalOrderQty   = 0.0;
        $totalReceivedQty = 0.0;

        $preview = [];
        $dateCols = ['pi_received_date', 'lc_issue_date', 'org_document_date', 'ex_factory_date', 'etd', 'eta', 'received_date'];
        foreach ($capture->rows as $idx => $r) {
            $rowOut = [];
            foreach ($this->canonicalColumns() as $col) {
                $hdr = $effectiveMapping[$col] ?? null;
                $val = null;
                if ($hdr) {
                    foreach ($r as $k => $v) {
                        if ($this->normalizeHeader((string) $k) === $this->normalizeHeader((string) $hdr)) {
                            $val = $v;
                            break;
                        }
                    }
                }
                // Parse numeric columns so the validator and totals use the real value.
                if (in_array($col, $numericCols, true)) {
                    $val = $this->parseNumber($val);
                }
                // Parse date columns so the validator works correctly with Excel dates like 1/6/2025
                if (in_array($col, $dateCols, true)) {
                    $val = $this->parseDate($val);
                }
                $rowOut[$col] = $val;
            }

            // Detect warnings: mapped numeric column had a raw value that couldn't be parsed.
            $warnings = [];
            foreach ($numericCols as $col) {
                $hdr = $effectiveMapping[$col] ?? null;
                if (!$hdr) continue;
                // Get the raw (un-parsed) cell value to check for non-numeric strings.
                $rawVal = null;
                foreach ($r as $k => $v) {
                    if ($this->normalizeHeader((string) $k) === $this->normalizeHeader((string) $hdr)) {
                        $rawVal = $v;
                        break;
                    }
                }
                if ($rawVal !== null && $rawVal !== '' && $rowOut[$col] === null) {
                    $warnings[$col] = 'Cannot parse as number: "' . $rawVal . '"';
                }
            }

            $totalOrderQty   += (float) ($rowOut['order_qty_kg'] ?? 0);
            $totalReceivedQty += (float) ($rowOut['received_qty'] ?? 0);

            $v = validator($rowOut, [
                'pi_number' => 'required|string|max:100',
                'supplier' => 'nullable|string|max:255',
                'merchandiser' => 'nullable|string|max:255',
                'merchant_team' => 'nullable|string|max:255',
                'yarn_count' => 'nullable|string|max:100',
                'booking_type' => 'nullable|string|max:10',
                'fiber_description' => 'nullable|string',
                'fiber' => 'nullable|string|max:255',
                'order_qty_kg' => 'nullable|numeric|min:0',
                'pi_received_date' => 'nullable|string',
                'lc_number' => 'nullable|string|max:100',
                'lc_issue_date' => 'nullable|string',
                'lc_factory' => 'nullable|string|max:50',
                'commercial_invoice' => 'nullable|string|max:100',
                'bl_qty_ctn' => 'nullable|numeric|min:0',
                'org_document_date' => 'nullable|string',
                'ex_factory_date' => 'nullable|string',
                'etd' => 'nullable|string',
                'eta' => 'nullable|string',
                'received_qty' => 'nullable|numeric|min:0',
                'received_date' => 'nullable|string',
                'remark' => 'nullable|string',
            ]);

            $preview[] = [
                'row'      => $idx + 2, // heading row is 1
                'data'     => $rowOut,
                'errors'   => $v->fails() ? $v->errors()->toArray() : (object) [],
                'warnings' => $warnings ?: (object) [],
            ];
        }

        Cache::put("pf_import:{$importId}:path", $path, now()->addHours(6));

        return response()->json([
            'import_id'       => $importId,
            'headers'         => $headers,
            'suggested_mapping' => $suggestedMapping,
            'canonical_columns' => $this->canonicalColumns(),
            'preview'         => $preview,
            'total_rows'      => count($preview),
            'totals'          => [
                'order_qty_kg' => round($totalOrderQty, 3),
                'received_qty' => round($totalReceivedQty, 3),
            ],
        ]);
    }

    public function confirm(Request $request)
    {
        $request->validate([
            'import_id' => 'required|string',
            'mapping' => 'required|array',
        ]);

        $importId = (string) $request->import_id;
        $path = Cache::get("pf_import:{$importId}:path");
        if (!$path || !Storage::disk('local')->exists($path)) {
            return response()->json(['message' => 'Import file expired. Please re-upload.'], 422);
        }

        $importBatch = $request->input('import_batch');
        if (!$importBatch) {
            $importBatch = 'PF-' . now()->format('Ymd-His') . '-' . Str::upper(Str::random(5));
        }

        $state = [
            'status'          => 'running',
            'import_batch'    => $importBatch,
            'success'         => 0,
            'failed'          => 0,
            'total_processed' => 0,
            'errors'          => [],
            'started_at'      => now()->toISOString(),
        ];
        Cache::put("pf_import:{$importId}:state", $state, now()->addHours(6));

        // Process synchronously — avoids file-not-found errors when web and
        // queue workers run in separate containers without a shared storage volume.
        @set_time_limit(300);

        $fullPath = Storage::disk('local')->path($path);
        try {
            $import = new ProcurementFollowupsUpsertImport($importId, (array) $request->mapping, $importBatch);
            Excel::import($import, $fullPath);

            $state = Cache::get("pf_import:{$importId}:state", $state);
            $state['status']      = 'completed';
            $state['finished_at'] = now()->toISOString();
            Cache::put("pf_import:{$importId}:state", $state, now()->addHours(6));
        } catch (\Throwable $e) {
            $state = Cache::get("pf_import:{$importId}:state", $state);
            $state['status']      = 'failed';
            $state['finished_at'] = now()->toISOString();
            $state['message']     = $e->getMessage();
            Cache::put("pf_import:{$importId}:state", $state, now()->addHours(6));
        } finally {
            // Clean up the uploaded temp file now that import is done
            Storage::disk('local')->delete($path);
        }

        return response()->json([
            'import_id'    => $importId,
            'import_batch' => $importBatch,
        ]);
    }

    public function progress(string $importId)
    {
        $state = Cache::get("pf_import:{$importId}:state");
        if (!$state) {
            return response()->json(['status' => 'expired'], 404);
        }
        return response()->json($state);
    }
}

