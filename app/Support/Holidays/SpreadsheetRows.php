<?php

namespace App\Support\Holidays;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

/**
 * Reads the rows of an uploaded .xlsx, .xls or .csv file.
 *
 * The sheet to read is chosen by name ("Holidays" by default, as in the
 * template; master-data imports pass their own) and otherwise the first sheet —
 * never "whichever tab was open when it was saved", which for a template would
 * be the instructions. Only a bounded block of cells is read, so a huge or
 * mostly-empty sheet can't exhaust memory.
 */
final class SpreadsheetRows
{
    public const SHEET_NAME = 'Holidays';
    public const MAX_ROWS = 3000;
    public const MAX_COLUMNS = 10;

    private const READERS = ['Xlsx', 'Xls', 'Csv'];

    /**
     * @param  string  $extension  What the upload was called (xlsx, xls or csv); the content must agree.
     * @param  string  $sheetName  The sheet to read when the workbook has it; otherwise the first.
     * @return list<list<mixed>> Sheet row 1 first. Date cells come back as Excel serial numbers.
     *
     * @throws \RuntimeException When the file is not a readable spreadsheet.
     */
    public static function read(string $path, string $extension, string $sheetName = self::SHEET_NAME): array
    {
        try {
            $type = IOFactory::identify($path, self::READERS);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Not a spreadsheet: '.$e->getMessage(), 0, $e);
        }

        // Plain text saved under an Excel name is a mistake, not a CSV.
        if ($type === 'Csv' && in_array(strtolower($extension), ['xlsx', 'xls'], true)) {
            throw new \RuntimeException("File is named .{$extension} but is plain text, not an Excel workbook.");
        }

        $reader = IOFactory::createReader($type);
        $reader->setReadDataOnly(true);
        $reader->setReadFilter(new class implements IReadFilter
        {
            // Untyped parameters: the installed PhpSpreadsheet's interface declares none.
            public function readCell($columnAddress, $row, $worksheetName = ''): bool
            {
                return $row <= SpreadsheetRows::MAX_ROWS
                    && Coordinate::columnIndexFromString($columnAddress) <= SpreadsheetRows::MAX_COLUMNS;
            }
        });

        if ($type !== 'Csv') {
            $names = $reader->listWorksheetNames($path);
            $named = array_filter($names, fn ($name) => strcasecmp($name, $sheetName) === 0);
            $reader->setLoadSheetsOnly([$named ? reset($named) : $names[0]]);
        }

        $book = $reader->load($path);
        $sheet = $book->getSheet(0);

        $last = max(1, min($sheet->getHighestRow(), self::MAX_ROWS));
        $rows = $sheet->rangeToArray('A1:'.Coordinate::stringFromColumnIndex(self::MAX_COLUMNS).$last, null, true, false, false);

        $book->disconnectWorksheets();

        return array_values($rows);
    }
}
