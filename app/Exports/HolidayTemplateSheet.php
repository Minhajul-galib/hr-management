<?php

namespace App\Exports;

use App\Support\Holidays\HolidayImporter;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * The sheet people type into: headings, then two highlighted sample rows. The
 * samples' Description marks them, so the import skips them if they are left in.
 */
class HolidayTemplateSheet implements FromArray, WithColumnWidths, WithEvents, WithHeadings, WithStyles, WithTitle
{
    /** Rows that get the date format ready, so typed dates are stored as real dates. */
    private const FORMATTED_ROWS = 1001;

    public function title(): string
    {
        return 'Holidays';
    }

    public function headings(): array
    {
        return ['Date', 'Holiday Name', 'End Date', 'Description'];
    }

    public function array(): array
    {
        // Real date cells, shown as yyyy-mm-dd by the column format below.
        $date = fn (?string $day) => $day === null ? null : ExcelDate::PHPToExcel(new \DateTimeImmutable($day));

        return array_map(
            fn (array $sample) => [$date($sample[0]), $sample[1], $date($sample[2]), $sample[3]],
            HolidayImporter::samples((int) now()->year),
        );
    }

    public function columnWidths(): array
    {
        return ['A' => 16, 'B' => 38, 'C' => 16, 'D' => 52];
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

                $sheet->getRowDimension(1)->setRowHeight(22);
                $sheet->freezePane('A2');

                // Date and End Date: show as yyyy-mm-dd and align left like text.
                foreach (['A', 'C'] as $column) {
                    $range = "{$column}2:{$column}".self::FORMATTED_ROWS;
                    $sheet->getStyle($range)->getNumberFormat()->setFormatCode('yyyy-mm-dd');
                    $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
                }

                // The sample rows: highlighted, with a note on how they are treated.
                $last = count(HolidayImporter::samples(2000)) + 1;
                $sheet->getStyle("A2:D{$last}")->applyFromArray([
                    'font' => ['italic' => true, 'color' => ['rgb' => '78350F']],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FEF3C7']],
                ]);
                $comment = $sheet->getComment('A2');
                $comment->setWidth('260pt')->setHeight('70pt');
                $comment->getText()->createTextRun('Sample rows')->getFont()->setBold(true);
                $comment->getText()->createTextRun("\nRows 2–{$last} show how to fill in this sheet. Type over them or delete them. While their Description still says \"".HolidayImporter::SAMPLE_MARK.'", the import skips them.');
            },
        ];
    }
}
