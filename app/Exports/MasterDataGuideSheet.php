<?php

namespace App\Exports;

use App\Support\MasterData\MasterDataImporter;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** Second sheet: how to fill in the first one, built from the master's fields (not imported). */
class MasterDataGuideSheet implements FromArray, WithColumnWidths, WithStrictNullComparison, WithStyles, WithTitle
{
    /** Row number of the column table's heading, for styling. */
    private int $columnsHeadRow = 0;

    /**
     * @param  list<array<string, mixed>>  $columns
     * @param  int  $samples  How many sample rows the data sheet starts with.
     */
    public function __construct(private readonly array $definition, private readonly array $columns, private readonly int $samples = 0) {}

    public function title(): string
    {
        return 'Instructions';
    }

    public function array(): array
    {
        $sheet = mb_substr($this->definition['import']['sheet'] ?? $this->definition['label'], 0, 31);
        $plural = mb_strtolower($this->definition['label']);
        $singular = mb_strtolower($this->definition['singular']);
        $match = implode(' + ', array_map(
            fn (string $key) => MasterDataImporter::heading(collect($this->columns)->firstWhere('key', $key)),
            MasterDataImporter::matchKeys($this->definition),
        ));
        $sampleRows = $this->samples > 1 ? 'Rows 2–'.($this->samples + 1) : 'Row 2';

        $rows = [
            ["How to fill in the {$sheet} sheet"],
            [],
            ["1. Type one {$singular} per row on the \"{$sheet}\" sheet. Keep the headings in row 1."],
        ];
        if ($this->samples) {
            $rows[] = ["2. {$sampleRows} (yellow) are samples that show how a row is filled in. Type over them or delete them — left exactly as they are, they are skipped."];
        }
        array_push(
            $rows,
            [($this->samples ? '3' : '2').". Rows are matched by {$match}: a new {$match} adds a {$singular}, an existing one updates that {$singular}. Codes must never repeat — other records will be linked by them."],
            [($this->samples ? '4' : '3').'. Leave an optional cell empty to keep what is already saved (a new row gets the default).'],
            [($this->samples ? '5' : '4').". Save as .xlsx (or .xls / .csv) and upload it on the {$this->definition['label']} page. Every row is shown for review first — fix problems there or skip them, then confirm the import."],
            [],
            ['Column', 'Required?', 'What to enter', 'Example'],
        );
        $this->columnsHeadRow = count($rows);

        $example = $this->definition['import']['examples'][0] ?? [];
        foreach ($this->columns as $field) {
            $rows[] = [
                MasterDataImporter::heading($field),
                ! empty($field['required']) ? 'Yes' : 'No',
                $this->describe($field),
                isset($example[$field['key']]) ? MasterDataExport::cell($field, $example[$field['key']]) : '',
            ];
        }

        $rows[] = [];
        $rows[] = ['Good to know'];
        $rows[] = ["• Uploading the same file twice is safe: unchanged {$plural} are left alone."];
        $rows[] = ['• At most '.number_format(MasterDataImporter::MAX_ROWS)." {$plural} per file. Split bigger lists into several files."];
        $rows[] = ['• Download the current list with Export, edit it, and upload it again to change many at once.'];
        $rows[] = ['• When a code is missing or already taken, the review suggests a free one that follows your numbering (e.g. D102 after D101).'];

        return $rows;
    }

    private function describe(array $field): string
    {
        $parts = [];
        $matchKeys = MasterDataImporter::matchKeys($this->definition);

        if ($field['type'] === 'boolean') {
            $parts[] = 'Yes or No.'.(array_key_exists('default', $field) ? ' Empty means '.($field['default'] ? 'Yes' : 'No').' for a new row.' : '');
        } elseif (isset($field['lookup'])) {
            $page = $field['lookup']['page'] ?? $field['label'];
            $codes = array_column(MasterDataImporter::lookupTargets($field), 'code');
            $parts[] = $field['type'] === 'multiselect'
                ? "Codes from the {$page} page, separated by commas (e.g. ".implode(', ', array_slice($codes ?: ['120', '652'], 0, 2)).'). Add them there first. Empty means none for a new row, and keeps what is saved for an existing one.'
                : "The code of a {$field['label']} from the {$page} page (it must be added there first).";
            if ($codes) {
                $parts[] = 'Current codes: '.implode(', ', array_slice($codes, 0, 20)).(count($codes) > 20 ? ', …' : '').'.';
            }
        } elseif ($field['type'] === 'select') {
            $parts[] = 'One of: '.implode(', ', array_column($field['options'], 'label')).'.';
            if (isset($field['default'])) {
                $default = collect($field['options'])->firstWhere('value', $field['default'])['label'] ?? null;
                $parts[] = $default ? "Empty means {$default} for a new row." : '';
            }
        } elseif ($field['type'] === 'number') {
            $parts[] = 'A number.';
        }

        if (! empty($field['uppercase'])) {
            $parts[] = 'Stored in capitals.';
        }
        if (! empty($field['lowercase'])) {
            $parts[] = 'Numbers and small letters only, with at least one number (e.g. 101). Capital letters are changed to small letters.';
        }
        if (in_array($field['key'], $matchKeys, true) && ! isset($field['lookup'])) {
            $others = array_filter($matchKeys, fn ($k) => $k !== $field['key']);
            $parts[] = $others
                ? 'Must be unique within its '.implode(', ', array_map(fn ($k) => collect($this->columns)->firstWhere('key', $k)['label'], $others)).'.'
                : 'Must be unique.';
        }
        foreach ($this->definition['unique'] ?? [] as $set) {
            if (end($set) === $field['key']) {
                $others = array_slice($set, 0, -1);
                $parts[] = $others
                    ? 'Must be unique within its '.implode(', ', array_map(fn ($k) => collect($this->columns)->firstWhere('key', $k)['label'] ?? $k, $others)).'.'
                    : 'Must be unique (capitals and small letters count as the same).';
            }
        }
        if (! empty($field['maxlength'])) {
            $parts[] = "Up to {$field['maxlength']} characters.";
        }
        if (! empty($field['help']) && ! isset($field['lookup']) && empty($field['lowercase'])) {
            $parts[] = $field['help'];
        }

        return implode(' ', array_unique(array_filter($parts)));
    }

    public function columnWidths(): array
    {
        return ['A' => 26, 'B' => 14, 'C' => 80, 'D' => 28, 'E' => 14];
    }

    public function styles(Worksheet $sheet): array
    {
        $first = $this->columnsHeadRow + 1;
        $last = $this->columnsHeadRow + count($this->columns);
        $sheet->getStyle("C{$first}:C{$last}")->getAlignment()->setWrapText(true);
        $sheet->getStyle("A{$first}:D{$last}")->getAlignment()->setVertical(Alignment::VERTICAL_TOP);

        return [
            1 => ['font' => ['bold' => true, 'size' => 14]],
            $this->columnsHeadRow => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4F46E5']],
            ],
        ];
    }
}
