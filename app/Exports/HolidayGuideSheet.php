<?php

namespace App\Exports;

use App\Support\Holidays\HolidayImporter;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** Second sheet of the template: how to fill it in, with example rows (not imported). */
class HolidayGuideSheet implements FromArray, WithColumnWidths, WithStyles, WithTitle
{
    public function title(): string
    {
        return 'Instructions';
    }

    public function array(): array
    {
        return [
            ['How to fill in the Holidays sheet'],
            [],
            ['1. Type one holiday per row on the "Holidays" sheet. Keep the headings in row 1.'],
            ['   Rows 2–3 (yellow) are samples. Type over them or delete them — while their Description still says "'.HolidayImporter::SAMPLE_MARK.'", they are skipped.'],
            ['2. Only the "Holidays" sheet is imported — this sheet is just a guide.'],
            ['3. Save the file as .xlsx (or .xls / .csv) and upload it on the Holidays Setup page.'],
            ['4. Rows that are skipped or rejected are listed with their row number after the upload.'],
            [],
            ['Column', 'Required?', 'What to enter', 'Example'],
            ['Date', 'Yes', 'First day of the holiday. Use a real date, or text as yyyy-mm-dd (day first if you use slashes: 16/12/2026).', '2026-12-16'],
            ['Holiday Name', 'Yes', 'Up to 255 characters.', 'Victory Day'],
            ['End Date', 'No', 'Last day, only for holidays that run for several days. Leave empty for a one-day holiday.', '2026-04-12'],
            ['Description', 'No', 'Any note, up to 1000 characters.', 'National holiday'],
            [],
            ['Good to know'],
            ['• A day that is already a holiday is skipped, so uploading the same file twice is safe.'],
            ['• Dates must fall between '.HolidayImporter::MIN_YEAR.' and '.HolidayImporter::MAX_YEAR.'; at most '.HolidayImporter::MAX_ROWS.' holidays per file.'],
            ['• Weekly off days (such as Friday) are set on the Holidays Setup page, not here.'],
        ];
    }

    public function columnWidths(): array
    {
        return ['A' => 18, 'B' => 28, 'C' => 70, 'D' => 28];
    }

    public function styles(Worksheet $sheet): array
    {
        $tableHead = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4F46E5']],
        ];

        $sheet->getStyle('C10:C13')->getAlignment()->setWrapText(true);
        $sheet->getStyle('A10:D13')->getAlignment()->setVertical(Alignment::VERTICAL_TOP);

        return [
            1 => ['font' => ['bold' => true, 'size' => 14]],
            9 => $tableHead,
            15 => ['font' => ['bold' => true]],
        ];
    }
}
