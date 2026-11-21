<?php

namespace App\Support\Holidays;

use App\Models\CalendarEvent;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Turns the rows of an uploaded sheet into holidays.
 *
 * It works on plain arrays (the reader hands it every row, first sheet row
 * first) so the rules can be tested without any file. Rows that can't be used
 * are reported with their sheet row number instead of being dropped silently.
 *
 * Columns, matched by heading in any order: Date, Holiday Name, End Date
 * (optional, for multi-day holidays) and Description (optional). A sheet with
 * no heading row at all is read as date, name — the old two-column CSV layout.
 */
final class HolidayImporter
{
    public const MAX_ROWS = 1000;

    private const HEADING_SEARCH_ROWS = 10;

    public const MIN_YEAR = 2000;

    public const MAX_YEAR = 2100;

    /** Accepted heading names per column, after normalising (lower case, symbols → "_"). */
    private const HEADINGS = [
        'date' => ['date', 'start_date', 'holiday_date', 'from', 'from_date', 'start'],
        'title' => ['holiday_name', 'name', 'title', 'holiday', 'occasion', 'event'],
        'end_date' => ['end_date', 'to', 'to_date', 'end', 'last_date', 'last_day'],
        'description' => ['description', 'remarks', 'remark', 'note', 'notes', 'details'],
    ];

    /**
     * The template starts with sample rows whose Description begins with this.
     * A sample left exactly as it is gets skipped, so leaving the samples in the
     * file does no harm, while a real "Victory Day" row is imported as usual.
     * A sample that was typed over is a real row; its leftover sample
     * description is dropped rather than saved.
     */
    public const SAMPLE_MARK = 'Sample row';

    /** Column order when the sheet has no heading row. */
    private const POSITIONAL = ['date' => 0, 'title' => 1];

    /**
     * Text dates, tried in order. A slash/dash/dot date is read day-first
     * (16/12/2026 is 16 December) — cells that Excel holds as real dates
     * never go through this and are unambiguous.
     */
    private const DATE_FORMATS = [
        'Y-m-d', 'Y/m/d', 'Y.m.d',
        'j/n/Y', 'j-n-Y', 'j.n.Y',
        'j M Y', 'j F Y', 'j-M-Y', 'j-F-Y',
        'M j, Y', 'F j, Y', 'M j Y', 'F j Y',
    ];

    /**
     * @param  list<list<mixed>>  $rows  Every sheet row, the first sheet row first.
     * @return array{
     *     total: int,
     *     created: list<array{row: int, title: string, start_date: string, end_date: ?string, days: int}>,
     *     skipped: list<array{row: int, title: string, start_date: string, reason: string}>,
     *     errors: list<array{row: int, message: string}>,
     *     fatal: ?string
     * }
     */
    public function import(array $rows, int $userId): array
    {
        $result = ['total' => 0, 'created' => [], 'skipped' => [], 'errors' => [], 'fatal' => null];

        $rows = array_values($rows);
        [$columns, $firstDataRow] = $this->locateColumns($rows);

        if ($columns === null) {
            $result['fatal'] = 'Could not find the "Date" and "Holiday Name" columns. Use the template — its first row holds the column headings.';

            return $result;
        }

        // 1. Read and check every row on its own.
        $candidates = [];
        for ($index = $firstDataRow; $index < count($rows); $index++) {
            $row = $rows[$index];
            if ($this->isBlank($row)) {
                continue;
            }

            if (++$result['total'] > self::MAX_ROWS) {
                $result['fatal'] = 'The sheet has more than '.self::MAX_ROWS.' rows. Split it into smaller files.';
                $result['created'] = $result['skipped'] = $result['errors'] = [];

                return $result;
            }

            $number = $index + 1; // sheet row number, as Excel shows it
            $parsed = $this->parseRow($row, $columns);

            if (is_string($parsed)) {
                $result['errors'][] = ['row' => $number, 'message' => $parsed];

                continue;
            }

            if (str_starts_with(mb_strtolower($parsed['description'] ?? ''), mb_strtolower(self::SAMPLE_MARK))) {
                if ($this->isSample($parsed)) {
                    $result['skipped'][] = [
                        'row' => $number,
                        'title' => $parsed['title'],
                        'start_date' => $parsed['first']->toDateString(),
                        'reason' => 'Sample row from the template, left as it is. Type over it or delete it.',
                        'sample' => true,
                    ];

                    continue;
                }
                $parsed['description'] = null;
            }

            $candidates[] = ['row' => $number] + $parsed;
        }

        // 2. Drop what clashes with an existing holiday, or with an earlier row.
        $accepted = [];
        $existing = $this->existingDays($candidates);
        $claimed = [];

        foreach ($candidates as $candidate) {
            $days = $this->days($candidate['first'], $candidate['last']);

            $clash = null;
            foreach ($days as $day) {
                if (isset($existing[$day])) {
                    $clash = 'Already a holiday on '.HolidayCalendar::label(Carbon::parse($day)).' ('.$existing[$day].').';
                    break;
                }
                if (isset($claimed[$day])) {
                    $clash = 'Overlaps row '.$claimed[$day].' of this file on '.HolidayCalendar::label(Carbon::parse($day)).'.';
                    break;
                }
            }

            if ($clash !== null) {
                $result['skipped'][] = [
                    'row' => $candidate['row'],
                    'title' => $candidate['title'],
                    'start_date' => $candidate['first']->toDateString(),
                    'reason' => $clash,
                ];

                continue;
            }

            foreach ($days as $day) {
                $claimed[$day] = $candidate['row'];
            }
            $accepted[] = $candidate + ['days' => count($days)];
        }

        // 3. Save what is left, all or nothing.
        DB::transaction(function () use ($accepted, $userId, &$result) {
            foreach ($accepted as $holiday) {
                CalendarEvent::create([
                    'user_id' => $userId,
                    'title' => $holiday['title'],
                    'description' => $holiday['description'],
                ] + HolidayCalendar::attributes($holiday['first'], $holiday['last']));

                $result['created'][] = [
                    'row' => $holiday['row'],
                    'title' => $holiday['title'],
                    'start_date' => $holiday['first']->toDateString(),
                    'end_date' => $holiday['days'] > 1 ? $holiday['last']->toDateString() : null,
                    'days' => $holiday['days'],
                ];
            }
        });

        return $result;
    }

    // ----- columns --------------------------------------------------------------

    /**
     * Find which column holds what.
     *
     * @param  list<list<mixed>>  $rows
     * @return array{0: ?array<string, int>, 1: int} [column map or null, index of first data row]
     */
    private function locateColumns(array $rows): array
    {
        $firstFilled = null;

        // Headings are normally row 1, but allow a title line or two above them.
        foreach (array_slice($rows, 0, self::HEADING_SEARCH_ROWS, true) as $index => $row) {
            if ($this->isBlank($row)) {
                continue;
            }
            $firstFilled ??= $index;

            $map = [];
            foreach ($row as $column => $heading) {
                $key = $this->headingKey($heading);
                if ($key !== null && ! isset($map[$key])) {
                    $map[$key] = $column;
                }
            }

            if (isset($map['date'], $map['title'])) {
                return [$map, $index + 1];
            }
        }

        // No headings at all: accept the old "date, name" layout if the first filled row is a date.
        if ($firstFilled !== null && $this->parseDate($rows[$firstFilled][self::POSITIONAL['date']] ?? null) !== null) {
            return [self::POSITIONAL, $firstFilled];
        }

        return [null, 0];
    }

    private function headingKey(mixed $heading): ?string
    {
        if (! is_string($heading) || trim($heading) === '') {
            return null;
        }

        $normal = Str::of($heading)->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->toString();
        $normal = preg_replace('/_optional$/', '', $normal);

        foreach (self::HEADINGS as $key => $names) {
            if (in_array($normal, $names, true)) {
                return $key;
            }
        }

        return null;
    }

    // ----- one row --------------------------------------------------------------

    /**
     * @param  list<mixed>  $row
     * @param  array<string, int>  $columns
     * @return array{first: Carbon, last: ?Carbon, title: string, description: ?string}|string The holiday, or why the row is unusable.
     */
    private function parseRow(array $row, array $columns): array|string
    {
        $cell = fn (string $key): mixed => isset($columns[$key]) ? ($row[$columns[$key]] ?? null) : null;

        $rawDate = $cell('date');
        if ($this->isEmptyCell($rawDate)) {
            return 'Date is missing.';
        }

        $first = $this->parseDate($rawDate);
        if ($first === null) {
            return 'Date "'.$this->shown($rawDate).'" is not a valid date between '.self::MIN_YEAR.' and '.self::MAX_YEAR.'. Use yyyy-mm-dd, e.g. 2026-12-16.';
        }

        $last = null;
        $rawEnd = $cell('end_date');
        if (! $this->isEmptyCell($rawEnd)) {
            $last = $this->parseDate($rawEnd);
            if ($last === null) {
                return 'End date "'.$this->shown($rawEnd).'" is not a valid date. Use yyyy-mm-dd, e.g. 2026-12-18.';
            }
            if ($last->lessThan($first)) {
                return 'End date is before the start date.';
            }
            if ($last->equalTo($first)) {
                $last = null;
            }
        }

        if ($last !== null && $first->diffInDays($last) >= 366) {
            return 'A holiday can run for at most 366 days.';
        }

        $title = $this->cleanText($cell('title'));
        if ($title === '') {
            return 'Holiday name is missing.';
        }
        if (mb_strlen($title) > 255) {
            return 'Holiday name is longer than 255 characters.';
        }

        $description = $this->cleanText($cell('description'));
        if (mb_strlen($description) > 1000) {
            return 'Description is longer than 1000 characters.';
        }

        return [
            'first' => $first,
            'last' => $last,
            'title' => $title,
            'description' => $description === '' ? null : $description,
        ];
    }

    /**
     * The template's sample rows: [date, name, end date, description], dated in $year.
     *
     * @return list<array{0: string, 1: string, 2: ?string, 3: string}>
     */
    public static function samples(int $year): array
    {
        return [
            ["{$year}-12-16", 'Victory Day', null, self::SAMPLE_MARK.' — a one-day holiday. Type over it or delete it.'],
            ["{$year}-04-10", 'Eid ul-Fitr', "{$year}-04-12", self::SAMPLE_MARK.' — a holiday of several days (fill in End Date). Type over it or delete it.'],
        ];
    }

    /** A row that still reads like one of the samples (in any year: the template may be from last year). */
    private function isSample(array $parsed): bool
    {
        foreach (self::samples($parsed['first']->year) as [$date, $title, $end, $description]) {
            if ($parsed['first']->toDateString() === $date
                && mb_strtolower($parsed['title']) === mb_strtolower($title)
                && $parsed['last']?->toDateString() === ($end === null ? null : $parsed['first']->year.substr($end, 4))
                && mb_strtolower((string) $parsed['description']) === mb_strtolower($description)) {
                return true;
            }
        }

        return false;
    }

    private function parseDate(mixed $value): ?Carbon
    {
        $date = match (true) {
            $value instanceof \DateTimeInterface => Carbon::instance($value),
            is_int($value) || is_float($value) => $this->fromExcelSerial($value),
            is_string($value) => $this->fromText($value),
            default => null,
        };

        if ($date === null || $date->year < self::MIN_YEAR || $date->year > self::MAX_YEAR) {
            return null;
        }

        return $date->startOfDay();
    }

    /** A real Excel date cell: days counted from 1900. */
    private function fromExcelSerial(int|float $serial): ?Carbon
    {
        // Rules out stray numbers such as 20261216 or 2026 typed into the date column.
        if ($serial < 1 || $serial > 80000) {
            return null;
        }

        try {
            return Carbon::instance(ExcelDate::excelToDateTimeObject($serial));
        } catch (\Throwable) {
            return null;
        }
    }

    private function fromText(string $text): ?Carbon
    {
        $text = trim($text);

        // Drop a time of day, e.g. "2026-12-16 00:00:00" or "2026-12-16T00:00".
        if (preg_match('/^(\d{4}-\d{1,2}-\d{1,2})[T\s]\d{1,2}:\d{2}/', $text, $match)) {
            $text = $match[1];
        }

        foreach (self::DATE_FORMATS as $format) {
            $date = \DateTimeImmutable::createFromFormat('!'.$format, $text);
            $problems = \DateTimeImmutable::getLastErrors();

            // 31/02/2026 "parses" but only with a warning that it rolled over.
            if ($date !== false && ($problems === false || ($problems['warning_count'] === 0 && $problems['error_count'] === 0))) {
                return Carbon::instance($date);
            }
        }

        return null;
    }

    // ----- helpers --------------------------------------------------------------

    /**
     * Existing holiday days across the span of the file, as day => holiday name.
     *
     * @param  list<array{first: Carbon, last: ?Carbon}>  $candidates
     * @return array<string, string>
     */
    private function existingDays(array $candidates): array
    {
        if ($candidates === []) {
            return [];
        }

        $from = min(array_map(fn ($c) => $c['first'], $candidates));
        $to = max(array_map(fn ($c) => $c['last'] ?? $c['first'], $candidates));

        $days = [];
        foreach (HolidayCalendar::within($from, $to) as $holiday) {
            foreach ($holiday->coveredDates() as $day) {
                $days[$day] ??= $holiday->title;
            }
        }

        return $days;
    }

    /** @return list<string> */
    private function days(Carbon $first, ?Carbon $last): array
    {
        $days = [];
        for ($day = $first->copy(); $day->lessThanOrEqualTo($last ?? $first); $day->addDay()) {
            $days[] = $day->toDateString();
        }

        return $days;
    }

    /** @param  list<mixed>  $row */
    private function isBlank(array $row): bool
    {
        foreach ($row as $cell) {
            if (! $this->isEmptyCell($cell)) {
                return false;
            }
        }

        return true;
    }

    private function isEmptyCell(mixed $cell): bool
    {
        return $cell === null || (is_string($cell) && trim($cell) === '');
    }

    /** Single-line text: control characters removed, runs of whitespace collapsed. */
    private function cleanText(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (! is_scalar($value)) {
            return '';
        }

        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $value) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    private function shown(mixed $value): string
    {
        return Str::limit($this->cleanText($value), 40);
    }
}
