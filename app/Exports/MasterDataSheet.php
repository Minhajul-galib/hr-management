<?php

namespace App\Exports;

use App\Support\MasterData\MasterDataImporter;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** The sheet the import reads: one heading row, then one record per row. */
class MasterDataSheet implements FromArray, WithColumnWidths, WithEvents, WithHeadings, WithStyles, WithTitle
{
    /** Excel refuses an in-cell drop-down list longer than this. */
    private const MAX_LIST_LENGTH = 250;

    /**
     * @param  list<array<string, mixed>>  $columns  Importable fields, in order.
     * @param  list<list<mixed>>  $rows
     * @param  int  $samples  How many of the first rows are sample rows (the template).
     * @param  array<string, array<int|string, string>>  $codes  Lookup field => codes it can take.
     */
    public function __construct(
        private readonly string $title,
        private readonly array $columns,
        private readonly array $rows,
        private readonly int $samples = 0,
        private readonly array $codes = [],
    ) {}

    public function title(): string
    {
        return mb_substr($this->title, 0, 31); // Excel's limit for sheet names
    }

    public function headings(): array
    {
        return array_map(fn (array $f) => MasterDataImporter::heading($f), $this->columns);
    }

    public function array(): array
    {
        return $this->rows;
    }

    public function columnWidths(): array
    {
        $widths = [];
        foreach ($this->columns as $i => $field) {
            $widths[Coordinate::stringFromColumnIndex($i + 1)] = match (true) {
                $field['type'] === 'textarea' => 52,
                in_array($field['key'], ['name', 'title'], true) => 38,
                $field['type'] === 'select' && isset($field['options']) => 30,
                default => max(14, mb_strlen(MasterDataImporter::heading($field)) + 6),
            };
        }

        return $widths;
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4F46E5']],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            ],
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $lastRow = MasterDataImporter::MAX_ROWS + 1;
                $lastColumn = Coordinate::stringFromColumnIndex(max(1, count($this->columns)));

                $sheet->getRowDimension(1)->setRowHeight(22);
                $sheet->freezePane('A2');

                foreach ($this->columns as $i => $field) {
                    $column = Coordinate::stringFromColumnIndex($i + 1);
                    $range = "{$column}2:{$column}{$lastRow}";

                    // Codes stay text, so Excel doesn't turn "001" into 1.
                    if (! empty($field['uppercase']) || ! empty($field['lowercase']) || isset($field['lookup'])) {
                        $sheet->getStyle($range)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
                    }

                    // Yes/No, fixed choices and (short) lists of codes get a drop-down.
                    $choices = match (true) {
                        $field['type'] === 'boolean' => ['Yes', 'No'],
                        $field['type'] === 'multiselect' => null, // a typed list of codes
                        isset($field['lookup']) => array_values($this->codes[$field['key']] ?? []),
                        $field['type'] === 'select' => array_column($field['options'] ?? [], 'label'),
                        default => null,
                    };
                    $list = $choices ? '"'.implode(',', $choices).'"' : null;

                    if ($list !== null && strlen($list) <= self::MAX_LIST_LENGTH) {
                        $validation = new DataValidation;
                        $validation->setType(DataValidation::TYPE_LIST)
                            ->setAllowBlank(true)
                            ->setShowDropDown(true) // shows the arrow (the writer flips Excel's inverted flag)
                            ->setShowErrorMessage(! isset($field['lookup'])) // a code added later is still fine
                            ->setErrorTitle(MasterDataImporter::heading($field))
                            ->setError('Pick a value from the list.')
                            ->setFormula1($list);
                        $sheet->setDataValidation($range, $validation);
                    }
                }

                // Sample rows: highlighted, with a note on how they are treated.
                if ($this->samples > 0) {
                    $last = $this->samples + 1;
                    $sheet->getStyle("A2:{$lastColumn}{$last}")->applyFromArray([
                        'font' => ['italic' => true, 'color' => ['rgb' => '78350F']],
                        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FEF3C7']],
                    ]);

                    $comment = $sheet->getComment('A2');
                    $comment->setWidth('260pt')->setHeight('70pt');
                    $comment->getText()->createTextRun('Sample rows')->getFont()->setBold(true);
                    $rows = $last > 2 ? "Rows 2–{$last} show" : 'Row 2 shows';
                    $comment->getText()->createTextRun("\n{$rows} how to fill in this sheet. Type over them or delete them. If they are left exactly as they are, the import skips them.");
                }
            },
        ];
    }
}
