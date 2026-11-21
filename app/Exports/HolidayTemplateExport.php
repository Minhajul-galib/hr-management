<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * The blank Excel file people fill in to bulk-add holidays.
 *
 * The import reads only the first sheet, so the sheet to fill in comes first
 * and the instructions (with example rows) sit on a second sheet.
 */
class HolidayTemplateExport implements WithMultipleSheets
{
    use Exportable;

    public function sheets(): array
    {
        return [
            new HolidayTemplateSheet,
            new HolidayGuideSheet,
        ];
    }
}
