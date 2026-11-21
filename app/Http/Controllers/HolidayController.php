<?php

namespace App\Http\Controllers;

use App\Exports\HolidayTemplateExport;
use App\Models\CalendarEvent;
use App\Models\HolidaySetting;
use App\Support\Holidays\HolidayCalendar;
use App\Support\Holidays\HolidayImporter;
use App\Support\Holidays\SpreadsheetRows;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Holidays Setup: the list of public holidays, the weekly off days, and the
 * Excel import. Every route here is behind the "manage holidays" permission.
 */
class HolidayController extends Controller
{
    private const MAX_UPLOAD_KB = 5120;

    /** Rows of each import list sent to the browser; the full counts are always sent. */
    private const REPORT_LIST_LIMIT = 100;

    public function index(Request $request): Response
    {
        $year = $this->year($request);
        $today = Carbon::today();

        $holidays = HolidayCalendar::within(Carbon::create($year, 1, 1), Carbon::create($year, 12, 31))
            ->map(fn (CalendarEvent $holiday) => $this->present($holiday, $year));

        $next = HolidayCalendar::within($today, $today->copy()->addYears(3))->first();

        return Inertia::render('Holidays/Setup', [
            'year' => $year,
            'years' => $this->years($year),
            'holidays' => $holidays->values(),
            'stats' => [
                'holidays' => $holidays->count(),
                'days' => $holidays->sum('days_in_year'),
            ],
            'next' => $next ? $this->present($next) : null,
            'settings' => ['weekday_off' => HolidaySetting::getCurrent()->offDays()],
            // Flashed by import(); only present on the request right after an upload.
            'importResult' => session('import_result'),
            'limits' => ['max_rows' => HolidayImporter::MAX_ROWS, 'max_mb' => self::MAX_UPLOAD_KB / 1024],
            'canManage' => true,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $holiday = $this->validated($request);
        $this->ensureFree($holiday['first'], $holiday['last']);

        CalendarEvent::create([
            'user_id' => $request->user()->id,
            'title' => $holiday['title'],
            'description' => $holiday['description'],
        ] + HolidayCalendar::attributes($holiday['first'], $holiday['last']));

        return back()->with('success', 'Holiday added.');
    }

    public function update(Request $request, CalendarEvent $holiday): RedirectResponse
    {
        abort_unless($holiday->is_holiday, 404);

        $new = $this->validated($request);
        $this->ensureFree($new['first'], $new['last'], $holiday->id);

        $holiday->update([
            'title' => $new['title'],
            'description' => $new['description'],
        ] + HolidayCalendar::attributes($new['first'], $new['last']));

        return back()->with('success', 'Holiday updated.');
    }

    public function destroy(CalendarEvent $holiday): RedirectResponse
    {
        abort_unless($holiday->is_holiday, 404);

        $holiday->delete();

        return back()->with('success', 'Holiday deleted.');
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'weekday_off' => ['present', 'array'],
            'weekday_off.*' => ['integer', 'between:0,6', 'distinct'],
        ]);

        HolidaySetting::saveOffDays($data['weekday_off']);

        return back()->with('success', 'Weekly off days saved.');
    }

    public function import(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'extensions:xlsx,xls,csv', 'max:'.self::MAX_UPLOAD_KB],
        ], [
            'file.required' => 'Choose an Excel file to upload.',
            'file.extensions' => 'Upload an Excel file (.xlsx or .xls) or a .csv file.',
            'file.max' => 'The file is too large — '.(self::MAX_UPLOAD_KB / 1024).' MB at most.',
        ]);

        $file = $request->file('file');

        try {
            $rows = SpreadsheetRows::read($file->getRealPath(), $file->getClientOriginalExtension());
        } catch (\Throwable $e) {
            Log::warning('Holiday import: could not read the uploaded sheet.', ['error' => $e->getMessage()]);

            return back()->with('error', 'That file could not be read. Upload an .xlsx, .xls or .csv file made from the template.');
        }

        $result = (new HolidayImporter)->import($rows, $request->user()->id);

        $created = count($result['created']);
        $skipped = count($result['skipped']);
        $errors = count($result['errors']);

        $request->session()->flash('import_result', [
            'file' => $file->getClientOriginalName(),
            'total' => $result['total'],
            'fatal' => $result['fatal'],
            'created_count' => $created,
            'skipped_count' => $skipped,
            'error_count' => $errors,
            'created' => array_slice($result['created'], 0, self::REPORT_LIST_LIMIT),
            'skipped' => array_slice($result['skipped'], 0, self::REPORT_LIST_LIMIT),
            'errors' => array_slice($result['errors'], 0, self::REPORT_LIST_LIMIT),
        ]);

        if ($result['fatal'] !== null) {
            return back()->with('error', $result['fatal']);
        }

        if ($result['total'] === 0) {
            return back()->with('error', 'The file has no holiday rows to import.');
        }

        $samples = count(array_filter($result['skipped'], fn ($row) => ! empty($row['sample'])));
        if ($samples === $result['total']) {
            return back()->with('error', "The file only has the template's sample rows. Type your own holidays over them and upload it again.");
        }

        if ($created === 0) {
            return back()->with('error', "Nothing was imported — {$skipped} skipped, {$errors} with problems.");
        }

        $message = 'Imported '.$created.' '.($created === 1 ? 'holiday' : 'holidays').'.';
        if ($skipped > 0) {
            $message .= " {$skipped} skipped.";
        }
        if ($errors > 0) {
            $message .= " {$errors} ".($errors === 1 ? 'row needs' : 'rows need').' attention.';
        }

        return back()->with('success', $message);
    }

    public function template(): BinaryFileResponse
    {
        return (new HolidayTemplateExport)->download('holidays-import-template.xlsx');
    }

    // ----- helpers --------------------------------------------------------------

    private function year(Request $request): int
    {
        $year = (int) $request->query('year', now()->year);

        return min(max($year, HolidayImporter::MIN_YEAR), HolidayImporter::MAX_YEAR);
    }

    /** Years offered in the picker: the ones with holidays, plus a window around today. */
    private function years(int $selected): array
    {
        $current = now()->year;

        return CalendarEvent::holidays()->pluck('start')
            ->map(fn ($start) => $start->year)
            ->merge(range($current - 2, $current + 2))
            ->push($selected)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Validate the add/edit form.
     *
     * The end date is the last day *off* ("10 Apr to 12 Apr" is three days), and
     * is left empty for a one-day holiday.
     *
     * @return array{title: string, description: ?string, first: Carbon, last: ?Carbon}
     */
    private function validated(Request $request): array
    {
        $bounds = ['after_or_equal:'.HolidayImporter::MIN_YEAR.'-01-01', 'before_or_equal:'.HolidayImporter::MAX_YEAR.'-12-31'];

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'start' => ['required', 'date', ...$bounds],
            'end' => ['nullable', 'date', ...$bounds],
        ], [
            'start.after_or_equal' => 'The date must be in '.HolidayImporter::MIN_YEAR.' or later.',
            'start.before_or_equal' => 'The date must be in '.HolidayImporter::MAX_YEAR.' or earlier.',
            'end.after_or_equal' => 'The end date must be in '.HolidayImporter::MIN_YEAR.' or later.',
            'end.before_or_equal' => 'The end date must be in '.HolidayImporter::MAX_YEAR.' or earlier.',
        ]);

        $first = Carbon::parse($data['start'])->startOfDay();
        $last = filled($data['end'] ?? null) ? Carbon::parse($data['end'])->startOfDay() : null;

        if ($last !== null && $last->lessThan($first)) {
            throw ValidationException::withMessages(['end' => 'The end date cannot be before the start date.']);
        }

        if ($last !== null && $first->diffInDays($last) >= 366) {
            throw ValidationException::withMessages(['end' => 'A holiday can run for at most 366 days.']);
        }

        return [
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'first' => $first,
            'last' => $last,
        ];
    }

    /** Refuse a holiday that overlaps one already on the calendar. */
    private function ensureFree(Carbon $first, ?Carbon $last, ?int $ignoreId = null): void
    {
        $clash = HolidayCalendar::conflict($first, $last, $ignoreId);

        if ($clash === null) {
            return;
        }

        $days = $clash->coveredDates();
        $when = HolidayCalendar::label(Carbon::parse($days[0]))
            .(count($days) > 1 ? ' – '.HolidayCalendar::label(Carbon::parse(end($days))) : '');

        throw ValidationException::withMessages([
            'start' => "“{$clash->title}” is already a holiday on {$when}, which overlaps these dates.",
        ]);
    }

    /** @return array<string, mixed> */
    private function present(CalendarEvent $holiday, ?int $year = null): array
    {
        $days = $holiday->coveredDates();

        return [
            'id' => $holiday->id,
            'title' => $holiday->title,
            'description' => $holiday->description,
            'start_date' => $days[0],
            'end_date' => count($days) > 1 ? end($days) : null,
            'days' => count($days),
            // Days that fall inside the year being viewed (a holiday can straddle New Year).
            'days_in_year' => $year === null ? count($days) : count(array_filter($days, fn ($day) => str_starts_with($day, "{$year}-"))),
        ];
    }
}
