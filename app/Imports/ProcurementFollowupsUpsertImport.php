<?php

namespace App\Imports;

use App\Models\ProcurementFollowup;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Concerns\OnEachRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Row;

class ProcurementFollowupsUpsertImport implements OnEachRow, WithHeadingRow, WithChunkReading, WithMultipleSheets
{
    public function __construct(
        private string $importId,
        private array $mapping,
        private string $importBatch,
    ) {
    }

    public function sheets(): array
    {
        return [0 => $this];
    }

    public function chunkSize(): int
    {
        return 200;
    }

    private function normalizeHeader(string $h): string
    {
        $h = trim(mb_strtolower($h));
        $h = preg_replace('/[\s\-\/]+/u', '_', $h);
        $h = preg_replace('/[^a-z0-9_]+/u', '', $h);
        return $h ?? '';
    }

    /**
     * Strip everything except lowercase letters and digits for fuzzy matching.
     * Maatwebsite WithHeadingRow uses Str::slug() which removes punctuation entirely
     * (e.g. "P/I #" → "pi", "Order Qty (KG)" → "order_qty_kg").
     * Our normalizeHeader keeps separators as underscores, so we also try an
     * alphanumeric-only comparison to bridge the gap.
     */
    private function alphaNum(string $h): string
    {
        return preg_replace('/[^a-z0-9]+/', '', mb_strtolower(trim($h)));
    }

    private function getCell(array $row, ?string $header)
    {
        if (!$header) return null;

        // 1. Direct hit (exact key)
        if (array_key_exists($header, $row)) {
            return $row[$header];
        }

        $targetNorm    = $this->normalizeHeader($header);
        $targetAlpha   = $this->alphaNum($header);

        foreach ($row as $k => $v) {
            $key = (string) $k;
            // 2. underscore-normalised match
            if ($this->normalizeHeader($key) === $targetNorm) {
                return $v;
            }
            // 3. alphanumeric-only match (handles Str::slug row keys vs original headers)
            if ($this->alphaNum($key) === $targetAlpha) {
                return $v;
            }
        }

        return null;
    }

    /**
     * Parse dates in multiple formats used in procurement spreadsheets:
     * DD.MM.YY, DD.MM.YYYY, DD/MM/YY, DD/MM/YYYY, YYYY-MM-DD, Excel serial, etc.
     */
    private function parseDate(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;

        // Excel numeric serial date (e.g. 45678)
        if (is_numeric($value) && (int) $value > 1000 && (int) $value < 100000) {
            try {
                return Carbon::createFromFormat('Y-m-d', '1899-12-30')
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
            'm/d/Y',    // 12/05/2024
            'd M Y',    // 05 Dec 2024
        ];

        foreach ($formats as $fmt) {
            try {
                return Carbon::createFromFormat($fmt, $str)->format('Y-m-d');
            } catch (\Throwable) {}
        }

        // Last resort: Carbon's flexible parse
        try {
            return Carbon::parse($str)->format('Y-m-d');
        } catch (\Throwable) {}

        return null;
    }

    /** Strip thousands separators and normalise to plain numeric string. */
    private function parseNumber(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        if (is_float($value) || is_int($value)) return (string) $value;

        // Remove spaces and common thousands separators (comma, apostrophe)
        $clean = str_replace([' ', ',', "'"], '', trim((string) $value));

        return is_numeric($clean) ? $clean : null;
    }

    public function onRow(Row $row): void
    {
        $rowIndex = $row->getIndex(); // 1-based, includes heading row already handled by WithHeadingRow
        $raw = $row->toArray();

        // Log first data row to verify key normalization
        if ($rowIndex === 2) {
            \Illuminate\Support\Facades\Log::debug('PF Import row keys: ' . implode(', ', array_keys($raw)));
            \Illuminate\Support\Facades\Log::debug('PF Import mapping: ' . json_encode($this->mapping));
        }

        $data = [
            'supplier'          => $this->getCell($raw, $this->mapping['supplier'] ?? null),
            'merchandiser'      => $this->getCell($raw, $this->mapping['merchandiser'] ?? null),
            'merchant_team'     => $this->getCell($raw, $this->mapping['merchant_team'] ?? null),
            'yarn_count'        => $this->getCell($raw, $this->mapping['yarn_count'] ?? null),
            'booking_type'      => $this->getCell($raw, $this->mapping['booking_type'] ?? null),
            'fiber_description' => $this->getCell($raw, $this->mapping['fiber_description'] ?? null),
            'fiber'             => $this->getCell($raw, $this->mapping['fiber'] ?? null),
            'order_qty_kg'      => $this->parseNumber($this->getCell($raw, $this->mapping['order_qty_kg'] ?? null)),
            'pi_number'         => $this->getCell($raw, $this->mapping['pi_number'] ?? null),
            'pi_received_date'  => $this->parseDate($this->getCell($raw, $this->mapping['pi_received_date'] ?? null)),
            'lc_number'         => $this->getCell($raw, $this->mapping['lc_number'] ?? null),
            'lc_issue_date'     => $this->parseDate($this->getCell($raw, $this->mapping['lc_issue_date'] ?? null)),
            'lc_factory'        => $this->getCell($raw, $this->mapping['lc_factory'] ?? null),
            'commercial_invoice'=> $this->getCell($raw, $this->mapping['commercial_invoice'] ?? null),
            'bl_qty_ctn'        => $this->parseNumber($this->getCell($raw, $this->mapping['bl_qty_ctn'] ?? null)),
            'org_document_date' => $this->parseDate($this->getCell($raw, $this->mapping['org_document_date'] ?? null)),
            'ex_factory_date'   => $this->parseDate($this->getCell($raw, $this->mapping['ex_factory_date'] ?? null)),
            'etd'               => $this->parseDate($this->getCell($raw, $this->mapping['etd'] ?? null)),
            'eta'               => $this->parseDate($this->getCell($raw, $this->mapping['eta'] ?? null)),
            'received_qty'      => $this->parseNumber($this->getCell($raw, $this->mapping['received_qty'] ?? null)),
            'received_date'     => $this->parseDate($this->getCell($raw, $this->mapping['received_date'] ?? null)),
            'remark'            => $this->getCell($raw, $this->mapping['remark'] ?? null),
        ];

        // Normalize strings
        foreach (['supplier', 'merchandiser', 'merchant_team', 'yarn_count', 'booking_type', 'fiber_description', 'fiber', 'pi_number', 'lc_number', 'lc_factory', 'commercial_invoice', 'remark'] as $k) {
            if (is_string($data[$k] ?? null)) {
                $data[$k] = trim($data[$k]);
                if ($data[$k] === '') $data[$k] = null;
            }
        }

        // Booking type normalization
        if (!empty($data['booking_type'])) {
            $bt = strtoupper((string) $data['booking_type']);
            if (in_array($bt, ['CF', 'CC'], true)) {
                $data['booking_type'] = $bt;
            }
        }

        // Auto-generate pi_number when missing so the row is still importable.
        // Key fields are hashed deterministically → same row on re-import gets the same ID.
        if (empty($data['pi_number'])) {
            $seed = implode('|', [
                $data['supplier'] ?? '',
                $data['merchant_team'] ?? '',
                $data['yarn_count'] ?? '',
                $data['booking_type'] ?? '',
                $data['order_qty_kg'] ?? '',
                $data['pi_received_date'] ?? '',
                $rowIndex,
            ]);
            $data['pi_number'] = 'NOPI-' . strtoupper(substr(md5($seed), 0, 14));
        }

        $validator = Validator::make($data, [
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

        if ($validator->fails()) {
            $stateKey = "pf_import:{$this->importId}:state";
            $state = Cache::get($stateKey, []);
            $errors = (array) ($state['errors'] ?? []);
            $errors[] = [
                'row' => $rowIndex,
                'pi_number' => (string) ($data['pi_number'] ?? ''),
                'errors' => $validator->errors()->toArray(),
            ];
            $state['errors'] = array_slice($errors, -250); // cap memory
            $state['failed'] = (int) ($state['failed'] ?? 0) + 1;
            $state['total_processed'] = (int) ($state['total_processed'] ?? 0) + 1;
            Cache::put($stateKey, $state, now()->addHours(6));
            return;
        }

        $validated = $validator->validated();

        $payload = array_merge($validated, [
            'import_batch' => $this->importBatch,
        ]);

        ProcurementFollowup::create($payload);

        $stateKey = "pf_import:{$this->importId}:state";
        $state = Cache::get($stateKey, []);
        $state['success'] = (int) ($state['success'] ?? 0) + 1;
        $state['total_processed'] = (int) ($state['total_processed'] ?? 0) + 1;
        Cache::put($stateKey, $state, now()->addHours(6));
    }
}

