<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\HolidaySetting;
use App\Models\User;
use App\Support\Holidays\HolidayCalendar;
use App\Support\Holidays\HolidayImporter;
use App\Support\Holidays\SpreadsheetRows;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class HolidaySetupTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(array $permissions): User
    {
        $role = Role::create(['name' => 'Role '.uniqid()]);
        $role->givePermissionTo(array_map(fn ($p) => Permission::findOrCreate($p), $permissions));

        $user = User::factory()->create();
        $user->syncRoles([$role]);

        return $user;
    }

    private function manager(): User
    {
        return $this->userWith(['manage holidays']);
    }

    private function holiday(string $title, string $first, ?string $last = null): CalendarEvent
    {
        return CalendarEvent::create([
            'user_id' => User::factory()->create()->id,
            'title' => $title,
            'description' => null,
        ] + HolidayCalendar::attributes(Carbon::parse($first), $last ? Carbon::parse($last) : null));
    }

    private function dates(string $title): array
    {
        return CalendarEvent::holidays()->where('title', $title)->firstOrFail()->coveredDates();
    }

    /** Build an upload from rows. DateTime cells become real Excel dates. */
    private function sheet(array $rows, string $extension = 'xlsx'): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $worksheet = $spreadsheet->getActiveSheet();

        foreach ($rows as $r => $row) {
            foreach (array_values($row) as $c => $value) {
                $cell = $worksheet->getCell([$c + 1, $r + 1]);

                if ($value instanceof \DateTimeInterface) {
                    $cell->setValue(ExcelDate::PHPToExcel($value));
                    $cell->getStyle()->getNumberFormat()->setFormatCode('yyyy-mm-dd');
                } elseif ($value !== null) {
                    $cell->setValueExplicit($value, is_string($value)
                        ? \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
                        : \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
                }
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'hol').'.'.$extension;
        IOFactory::createWriter($spreadsheet, ucfirst($extension))->save($path);

        return new UploadedFile($path, "holidays.{$extension}", null, null, true);
    }

    private function csv(string $contents, string $name = 'holidays.csv'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'hol').'.csv';
        file_put_contents($path, $contents);

        return new UploadedFile($path, $name, 'text/csv', null, true);
    }

    private function upload(User $user, UploadedFile $file)
    {
        return $this->actingAs($user)->post(route('calendar.holidays.import'), ['file' => $file]);
    }

    // ----- access ------------------------------------------------------------

    public function test_every_holiday_route_needs_the_manage_holidays_permission(): void
    {
        $holiday = $this->holiday('Victory Day', '2026-12-16');
        $viewer = $this->userWith(['view calendar']);

        $this->actingAs($viewer);
        $this->get(route('calendar.holidays.setup'))->assertForbidden();
        $this->get(route('calendar.holidays.template'))->assertForbidden();
        $this->post(route('calendar.holidays.store'), ['title' => 'X', 'start' => '2026-01-01'])->assertForbidden();
        $this->put(route('calendar.holidays.update', $holiday), ['title' => 'X', 'start' => '2026-01-01'])->assertForbidden();
        $this->delete(route('calendar.holidays.destroy', $holiday))->assertForbidden();
        $this->post(route('calendar.holidays.settings.update'), ['weekday_off' => [5]])->assertForbidden();
        $this->post(route('calendar.holidays.import'), ['file' => $this->csv("2026-01-01,X\n")])->assertForbidden();

        $this->assertDatabaseCount('calendar_events', 1);
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get(route('calendar.holidays.setup'))->assertRedirect(route('login'));
    }

    // ----- the page ------------------------------------------------------------

    public function test_setup_page_lists_the_requested_years_holidays(): void
    {
        $this->holiday('Victory Day', '2026-12-16');
        $this->holiday('Eid ul-Fitr', '2026-04-10', '2026-04-12');
        $this->holiday('Year end', '2025-12-31', '2026-01-02');
        $this->holiday('Next year', '2027-01-01');

        $this->actingAs($this->manager())
            ->get(route('calendar.holidays.setup', ['year' => 2026]))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Holidays/Setup')
                ->where('year', 2026)
                ->has('holidays', 3)
                ->where('holidays.0.title', 'Year end')
                ->where('holidays.0.days', 3)
                ->where('holidays.0.days_in_year', 2)
                ->where('holidays.1.title', 'Eid ul-Fitr')
                ->where('holidays.1.start_date', '2026-04-10')
                ->where('holidays.1.end_date', '2026-04-12')
                ->where('holidays.2.end_date', null)
                ->where('stats.holidays', 3)
                ->where('stats.days', 6)
                ->where('years', fn ($years) => collect($years)->contains(2025) && collect($years)->contains(2027))
                ->etc());
    }

    public function test_year_is_clamped_to_a_sane_range(): void
    {
        $this->actingAs($this->manager())
            ->get(route('calendar.holidays.setup', ['year' => 99999]))
            ->assertInertia(fn (Assert $page) => $page->where('year', HolidayImporter::MAX_YEAR)->etc());
    }

    public function test_next_holiday_is_the_first_one_not_yet_over(): void
    {
        Carbon::setTestNow('2026-12-10 10:00:00');
        $this->holiday('Past', '2026-12-01');
        $this->holiday('Victory Day', '2026-12-16');

        $this->actingAs($this->manager())
            ->get(route('calendar.holidays.setup'))
            ->assertInertia(fn (Assert $page) => $page->where('next.title', 'Victory Day')->etc());

        Carbon::setTestNow();
    }

    public function test_page_folds_the_legacy_all_fridays_flag_into_weekly_off(): void
    {
        HolidaySetting::create(['all_fridays' => true, 'weekday_off' => [0]]);

        $this->actingAs($this->manager())
            ->get(route('calendar.holidays.setup'))
            ->assertInertia(fn (Assert $page) => $page->where('settings.weekday_off', [0, 5])->etc());
    }

    // ----- add / edit / delete ----------------------------------------------------

    public function test_a_single_day_holiday_is_saved_as_a_whole_day(): void
    {
        $manager = $this->manager();

        $this->actingAs($manager)
            ->post(route('calendar.holidays.store'), ['title' => 'Victory Day', 'description' => 'National', 'start' => '2026-12-16'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $holiday = CalendarEvent::holidays()->firstOrFail();
        $this->assertSame('2026-12-16 00:00:00', $holiday->start->toDateTimeString());
        $this->assertNull($holiday->end);
        $this->assertTrue($holiday->all_day);
        $this->assertSame($manager->id, $holiday->user_id);
        $this->assertSame(['2026-12-16'], $holiday->coveredDates());
    }

    public function test_a_multi_day_holiday_covers_every_day_up_to_the_end_date(): void
    {
        $this->actingAs($this->manager())
            ->post(route('calendar.holidays.store'), ['title' => 'Eid ul-Fitr', 'start' => '2026-04-10', 'end' => '2026-04-12'])
            ->assertSessionHasNoErrors();

        $this->assertSame(['2026-04-10', '2026-04-11', '2026-04-12'], $this->dates('Eid ul-Fitr'));
        $this->assertSame('2026-04-12 23:59:59', CalendarEvent::holidays()->first()->end->toDateTimeString());
    }

    public function test_an_end_date_equal_to_the_start_is_just_a_one_day_holiday(): void
    {
        $this->actingAs($this->manager())
            ->post(route('calendar.holidays.store'), ['title' => 'Victory Day', 'start' => '2026-12-16', 'end' => '2026-12-16'])
            ->assertSessionHasNoErrors();

        $this->assertNull(CalendarEvent::holidays()->first()->end);
    }

    public function test_the_calendar_pages_add_holiday_form_still_works(): void
    {
        // The Calendar page posts its whole event form, with date-times and extra fields.
        $this->actingAs($this->manager())
            ->post(route('calendar.holidays.store'), [
                'title' => 'Victory Day',
                'description' => '',
                'start' => '2026-12-16T09:30',
                'end' => '',
                'all_day' => false,
                'color' => '#3788d8',
                'reminder_at' => '',
                'is_holiday' => true,
            ])
            ->assertSessionHasNoErrors();

        $holiday = CalendarEvent::holidays()->firstOrFail();
        $this->assertSame('2026-12-16 00:00:00', $holiday->start->toDateTimeString());
        $this->assertSame('#dc2626', $holiday->color);
        $this->assertNull($holiday->description);
    }

    public function test_overlapping_an_existing_holiday_is_refused(): void
    {
        $this->holiday('Eid ul-Fitr', '2026-04-10', '2026-04-12');
        $this->actingAs($manager = $this->manager());

        foreach ([
            ['2026-04-11', null],          // middle of the existing holiday
            ['2026-04-12', null],          // its last day
            ['2026-04-08', '2026-04-10'],  // runs into its first day
            ['2026-04-09', '2026-04-20'],  // swallows it
        ] as [$start, $end]) {
            $this->post(route('calendar.holidays.store'), ['title' => 'Clash', 'start' => $start, 'end' => $end])
                ->assertSessionHasErrors('start');
        }

        $this->assertSame(1, CalendarEvent::holidays()->count());

        // The day after is free.
        $this->post(route('calendar.holidays.store'), ['title' => 'Day after', 'start' => '2026-04-13'])
            ->assertSessionHasNoErrors();
        $this->assertSame(2, CalendarEvent::holidays()->count());
    }

    public function test_a_calendar_style_exclusive_end_does_not_block_the_next_day(): void
    {
        // Some holidays were saved as 10 Apr 00:00 → 13 Apr 00:00, meaning the 10th–12th.
        CalendarEvent::create([
            'user_id' => User::factory()->create()->id,
            'title' => 'Eid ul-Fitr',
            'start' => '2026-04-10 00:00:00',
            'end' => '2026-04-13 00:00:00',
            'all_day' => true,
            'is_holiday' => true,
        ]);

        $this->assertSame(['2026-04-10', '2026-04-11', '2026-04-12'], $this->dates('Eid ul-Fitr'));

        $this->actingAs($this->manager())
            ->post(route('calendar.holidays.store'), ['title' => 'Day after', 'start' => '2026-04-13'])
            ->assertSessionHasNoErrors();
    }

    public function test_the_form_is_validated(): void
    {
        $this->actingAs($this->manager());

        $this->post(route('calendar.holidays.store'), ['start' => '2026-01-01'])->assertSessionHasErrors('title');
        $this->post(route('calendar.holidays.store'), ['title' => 'X'])->assertSessionHasErrors('start');
        $this->post(route('calendar.holidays.store'), ['title' => 'X', 'start' => 'not a date'])->assertSessionHasErrors('start');
        $this->post(route('calendar.holidays.store'), ['title' => str_repeat('a', 256), 'start' => '2026-01-01'])->assertSessionHasErrors('title');
        $this->post(route('calendar.holidays.store'), ['title' => 'X', 'start' => '2026-04-12', 'end' => '2026-04-10'])->assertSessionHasErrors('end');
        $this->post(route('calendar.holidays.store'), ['title' => 'X', 'start' => '1999-12-31'])->assertSessionHasErrors('start');
        $this->post(route('calendar.holidays.store'), ['title' => 'X', 'start' => '2026-01-01', 'end' => '2028-01-01'])->assertSessionHasErrors('end');

        $this->assertSame(0, CalendarEvent::count());
    }

    public function test_a_holiday_can_be_edited_and_may_overlap_its_own_old_dates(): void
    {
        $eid = $this->holiday('Eid', '2026-04-10', '2026-04-12');
        $this->holiday('Other', '2026-04-20');
        $this->actingAs($this->manager());

        // Shrinking onto its own days is fine.
        $this->put(route('calendar.holidays.update', $eid), ['title' => 'Eid ul-Fitr', 'description' => 'Updated', 'start' => '2026-04-11', 'end' => '2026-04-12'])
            ->assertSessionHasNoErrors();

        $eid->refresh();
        $this->assertSame('Eid ul-Fitr', $eid->title);
        $this->assertSame('Updated', $eid->description);
        $this->assertSame(['2026-04-11', '2026-04-12'], $eid->coveredDates());

        // Moving onto another holiday is not.
        $this->put(route('calendar.holidays.update', $eid), ['title' => 'Eid', 'start' => '2026-04-19', 'end' => '2026-04-21'])
            ->assertSessionHasErrors('start');
        $this->assertSame(['2026-04-11', '2026-04-12'], $eid->fresh()->coveredDates());
    }

    public function test_a_holiday_can_be_deleted(): void
    {
        $holiday = $this->holiday('Victory Day', '2026-12-16');

        $this->actingAs($this->manager())
            ->delete(route('calendar.holidays.destroy', $holiday))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('calendar_events', ['id' => $holiday->id]);
    }

    public function test_the_holiday_routes_never_touch_ordinary_events(): void
    {
        $meeting = CalendarEvent::create([
            'user_id' => User::factory()->create()->id,
            'title' => 'Team meeting',
            'start' => '2026-05-05 10:00:00',
            'is_holiday' => false,
        ]);

        $this->actingAs($this->manager());
        $this->put(route('calendar.holidays.update', $meeting), ['title' => 'Hijacked', 'start' => '2026-05-05'])->assertNotFound();
        $this->delete(route('calendar.holidays.destroy', $meeting))->assertNotFound();

        $this->assertSame('Team meeting', $meeting->fresh()->title);
    }

    // ----- weekly off ----------------------------------------------------------

    public function test_weekly_off_days_are_saved_and_the_friday_flag_follows(): void
    {
        $this->actingAs($this->manager());

        $this->post(route('calendar.holidays.settings.update'), ['weekday_off' => [6, 5]])->assertSessionHasNoErrors();
        $settings = HolidaySetting::firstOrFail();
        $this->assertSame([5, 6], $settings->weekday_off);
        $this->assertTrue($settings->all_fridays);

        $this->post(route('calendar.holidays.settings.update'), ['weekday_off' => [0]])->assertSessionHasNoErrors();
        $this->assertSame(1, HolidaySetting::count());
        $this->assertFalse($settings->fresh()->all_fridays);

        $this->post(route('calendar.holidays.settings.update'), ['weekday_off' => []])->assertSessionHasNoErrors();
        $this->assertSame([], $settings->fresh()->weekday_off);
    }

    public function test_weekly_off_days_are_validated(): void
    {
        $this->actingAs($this->manager());

        $this->post(route('calendar.holidays.settings.update'), ['weekday_off' => [7]])->assertSessionHasErrors('weekday_off.0');
        $this->post(route('calendar.holidays.settings.update'), ['weekday_off' => [1, 1]])->assertSessionHasErrors('weekday_off.0');
        $this->post(route('calendar.holidays.settings.update'), [])->assertSessionHasErrors('weekday_off');
    }

    // ----- calendar page ---------------------------------------------------------

    public function test_the_calendar_marks_every_day_of_a_multi_day_holiday(): void
    {
        $this->holiday('Eid ul-Fitr', '2026-04-10', '2026-04-12');

        $this->actingAs($this->userWith(['view calendar']))
            ->get(route('calendar.index'))
            ->assertInertia(fn (Assert $page) => $page->where('events', fn ($events) => collect($events)->contains(
                fn ($event) => $event['title'] === 'Eid ul-Fitr'
                    && $event['extendedProps']['dates'] === ['2026-04-10', '2026-04-11', '2026-04-12']
            ))->etc());
    }

    public function test_holiday_managers_can_edit_and_delete_holidays_from_the_calendar_routes(): void
    {
        $holiday = $this->holiday('Victory Day', '2026-12-16'); // created by somebody else

        $this->actingAs($this->userWith(['view calendar']))
            ->patch(route('calendar.events.update', $holiday), ['title' => 'Nope'])->assertForbidden();
        $this->delete(route('calendar.events.destroy', $holiday))->assertForbidden();

        $this->actingAs($this->manager())
            ->patch(route('calendar.events.update', $holiday), ['title' => 'Victory Day (updated)'])->assertSessionHasNoErrors();
        $this->assertSame('Victory Day (updated)', $holiday->fresh()->title);

        $this->delete(route('calendar.events.destroy', $holiday))->assertSessionHas('success');
        $this->assertDatabaseMissing('calendar_events', ['id' => $holiday->id]);
    }

    public function test_personal_events_still_belong_to_their_owner(): void
    {
        $owner = $this->userWith(['view calendar']);
        $event = CalendarEvent::create(['user_id' => $owner->id, 'title' => 'Mine', 'start' => '2026-05-05 10:00:00']);

        // Holiday managers have no say over other people's personal events.
        $this->actingAs($this->manager())->delete(route('calendar.events.destroy', $event))->assertForbidden();
        $this->actingAs($owner)->delete(route('calendar.events.destroy', $event))->assertSessionHas('success');
    }

    // ----- import: Excel and CSV -------------------------------------------------

    public function test_an_xlsx_file_with_real_date_cells_is_imported(): void
    {
        $file = $this->sheet([
            ['Date', 'Holiday Name', 'End Date', 'Description'],
            [Carbon::parse('2026-12-16'), 'Victory Day', null, 'National holiday'],
            [Carbon::parse('2026-04-10'), 'Eid ul-Fitr', Carbon::parse('2026-04-12'), null],
            ['2026-12-25', 'Christmas Day', null, null],   // text date
        ]);

        $this->upload($this->manager(), $file)
            ->assertSessionHas('success', 'Imported 3 holidays.')
            ->assertSessionHas('import_result', fn ($r) => $r['created_count'] === 3 && $r['error_count'] === 0 && $r['file'] === 'holidays.xlsx');

        $this->assertSame(['2026-12-16'], $this->dates('Victory Day'));
        $this->assertSame(['2026-04-10', '2026-04-11', '2026-04-12'], $this->dates('Eid ul-Fitr'));
        $this->assertSame(['2026-12-25'], $this->dates('Christmas Day'));
        $this->assertSame('National holiday', CalendarEvent::where('title', 'Victory Day')->value('description'));
        $this->assertSame(3, CalendarEvent::holidays()->where('is_holiday', true)->where('all_day', true)->count());
    }

    public function test_the_templates_sample_rows_are_skipped_but_a_real_row_like_them_is_imported(): void
    {
        $manager = $this->manager();
        $template = $this->actingAs($manager)->get(route('calendar.holidays.template'))->assertOk();
        $path = $template->baseResponse->getFile()->getPathname();

        $rows = SpreadsheetRows::read($path, 'xlsx');
        $this->assertSame('Victory Day', $rows[1][1]);
        $this->assertSame('Eid ul-Fitr', $rows[2][1]);

        // The untouched template adds nothing.
        $this->upload($manager, UploadedFile::fake()->createWithContent('holidays.xlsx', file_get_contents($path)))
            ->assertSessionHas('import_result', fn ($r) => $r['created_count'] === 0 && $r['skipped_count'] === 2
                && str_contains($r['skipped'][0]['reason'], 'Sample row'));

        // A template downloaded last year still has its samples recognised.
        $lastYear = now()->year - 1;
        $this->upload($manager, $this->sheet([
            ['Date', 'Holiday Name', 'End Date', 'Description'],
            [Carbon::parse("{$lastYear}-12-16"), 'Victory Day', null, HolidayImporter::samples($lastYear)[0][3]],
        ]))->assertSessionHas('import_result', fn ($r) => $r['skipped_count'] === 1);
        $this->assertSame(0, CalendarEvent::holidays()->count());

        // The same holiday with its own description is a real row.
        $this->upload($manager, $this->sheet([
            ['Date', 'Holiday Name', 'End Date', 'Description'],
            [Carbon::parse(now()->year.'-12-16'), 'Victory Day', null, 'National holiday'],
        ]))->assertSessionHas('import_result', fn ($r) => $r['created_count'] === 1);
    }

    public function test_an_old_xls_file_is_imported(): void
    {
        $file = $this->sheet([
            ['Date', 'Holiday Name'],
            [Carbon::parse('2026-03-26'), 'Independence Day'],
        ], 'xls');

        $this->upload($this->manager(), $file)->assertSessionHas('success', 'Imported 1 holiday.');
        $this->assertSame(['2026-03-26'], $this->dates('Independence Day'));
    }

    public function test_a_csv_file_is_still_accepted(): void
    {
        $csv = "Date,Holiday Name,End Date,Description\n2026-12-16,Victory Day,,\n10/04/2026,Eid ul-Fitr,12/04/2026,\"Three days, religious\"\n";

        $this->upload($this->manager(), $this->csv($csv))->assertSessionHas('success', 'Imported 2 holidays.');

        $this->assertSame(['2026-04-10', '2026-04-11', '2026-04-12'], $this->dates('Eid ul-Fitr'));
        $this->assertSame('Three days, religious', CalendarEvent::where('title', 'Eid ul-Fitr')->value('description'));
    }

    public function test_the_old_headerless_two_column_csv_still_imports(): void
    {
        $this->upload($this->manager(), $this->csv("2026-01-26,Republic Day\n2026-03-26,Independence Day\n"))
            ->assertSessionHas('success', 'Imported 2 holidays.');

        $this->assertSame(['2026-01-26'], $this->dates('Republic Day'));
    }

    public function test_columns_can_be_in_any_order_under_a_title_line(): void
    {
        $file = $this->sheet([
            ['Public holidays 2026'],
            [],
            ['Holiday Name', 'Notes', 'Date', 'End Date (optional)'],
            ['Victory Day', 'National', Carbon::parse('2026-12-16'), null],
        ]);

        $this->upload($this->manager(), $file)->assertSessionHas('success');

        $this->assertSame('National', CalendarEvent::where('title', 'Victory Day')->value('description'));
        $this->assertSame(['2026-12-16'], $this->dates('Victory Day'));
    }

    public function test_the_import_reports_what_it_skipped_and_why(): void
    {
        $this->holiday('Victory Day', '2026-12-16');

        $file = $this->sheet([
            ['Date', 'Holiday Name', 'End Date'],                              // 1
            ['2026-12-16', 'Victory Day again', null],                         // 2 already on the calendar
            ['2026-01-26', 'Republic Day', null],                              // 3 fine
            ['2026-01-26', 'Republic Day twice', null],                        // 4 same day as row 3
            ['not a date', 'Mystery', null],                                   // 5 bad date
            [null, 'No date', null],                                           // 6 missing date
            ['2026-05-05', null, null],                                        // 7 missing name
            ['2026-06-10', 'Backwards', '2026-06-08'],                         // 8 end before start
            [null, null, null],                                                // 9 blank, ignored
            ['2026-04-10', 'Eid ul-Fitr', '2026-04-12'],                       // 10 fine
            ['2026-04-12', 'Overlaps Eid', null],                              // 11 inside row 10
        ]);

        $this->upload($this->manager(), $file)
            ->assertSessionHas('success', 'Imported 2 holidays. 3 skipped. 4 rows need attention.')
            ->assertSessionHas('import_result', function ($r) {
                return $r['total'] === 9
                    && $r['created_count'] === 2
                    && collect($r['skipped'])->pluck('row')->all() === [2, 4, 11]
                    && str_contains($r['skipped'][0]['reason'], 'Victory Day')
                    && str_contains($r['skipped'][1]['reason'], 'row 3')
                    && collect($r['errors'])->pluck('row')->all() === [5, 6, 7, 8]
                    && str_contains($r['errors'][2]['message'], 'name is missing')
                    && str_contains($r['errors'][3]['message'], 'before');
            });

        $this->assertSame(['Eid ul-Fitr', 'Republic Day', 'Victory Day'], CalendarEvent::holidays()->orderBy('title')->pluck('title')->all());
    }

    public function test_uploading_the_same_file_twice_adds_nothing_the_second_time(): void
    {
        $manager = $this->manager();
        $rows = [['Date', 'Holiday Name'], [Carbon::parse('2026-12-16'), 'Victory Day']];

        $this->upload($manager, $this->sheet($rows))->assertSessionHas('success');
        $this->upload($manager, $this->sheet($rows))
            ->assertSessionHas('error', 'Nothing was imported — 1 skipped, 0 with problems.');

        $this->assertSame(1, CalendarEvent::holidays()->count());
    }

    public function test_a_sheet_without_a_date_and_name_column_is_rejected(): void
    {
        $this->upload($this->manager(), $this->sheet([['Employee', 'Salary'], ['Rahim', 50000]]))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Could not find'));

        $this->assertSame(0, CalendarEvent::count());
    }

    public function test_a_sheet_with_only_headings_says_so(): void
    {
        $this->upload($this->manager(), $this->sheet([['Date', 'Holiday Name']]))
            ->assertSessionHas('error', 'The file has no holiday rows to import.');
    }

    public function test_too_many_rows_are_refused_without_importing_any(): void
    {
        $rows = [['Date', 'Holiday Name']];
        for ($i = 0; $i <= HolidayImporter::MAX_ROWS; $i++) {
            $rows[] = [Carbon::parse('2026-01-01')->addDays($i % 300), "Holiday {$i}"];
        }

        $this->upload($this->manager(), $this->sheet($rows))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'more than '.HolidayImporter::MAX_ROWS));

        $this->assertSame(0, CalendarEvent::count());
    }

    public function test_other_file_types_are_rejected(): void
    {
        $manager = $this->manager();

        foreach (['holidays.pdf', 'holidays.txt', 'holidays.docx', 'holidays.php'] as $name) {
            $file = UploadedFile::fake()->createWithContent($name, "2026-01-01,X\n");
            $this->actingAs($manager)->post(route('calendar.holidays.import'), ['file' => $file])
                ->assertSessionHasErrors('file');
        }

        $this->actingAs($manager)->post(route('calendar.holidays.import'), [])->assertSessionHasErrors('file');
        $this->assertSame(0, CalendarEvent::count());
    }

    public function test_an_oversized_file_is_rejected(): void
    {
        $file = UploadedFile::fake()->create('holidays.xlsx', 6000);

        $this->actingAs($this->manager())->post(route('calendar.holidays.import'), ['file' => $file])
            ->assertSessionHasErrors('file');
    }

    public function test_a_corrupt_spreadsheet_gives_a_clear_error_instead_of_a_crash(): void
    {
        $file = UploadedFile::fake()->createWithContent('holidays.xlsx', 'this is not a spreadsheet');

        $this->actingAs($this->manager())->post(route('calendar.holidays.import'), ['file' => $file])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'could not be read'));

        $this->assertSame(0, CalendarEvent::count());
    }

    public function test_the_holidays_sheet_is_read_even_when_another_tab_was_active_when_saved(): void
    {
        // The template's second tab is the instructions; Excel remembers whichever tab was open.
        $book = new Spreadsheet;
        $book->getActiveSheet()->setTitle('Holidays')->fromArray([['Date', 'Holiday Name'], ['2026-12-16', 'Victory Day']]);
        $book->createSheet()->setTitle('Instructions')->fromArray([['How to fill this in'], ['Date', 'Required']]);
        $book->setActiveSheetIndex(1);

        $path = tempnam(sys_get_temp_dir(), 'hol').'.xlsx';
        IOFactory::createWriter($book, 'Xlsx')->save($path);

        $this->upload($this->manager(), new UploadedFile($path, 'holidays.xlsx', null, null, true))
            ->assertSessionHas('success', 'Imported 1 holiday.');
        $this->assertSame(['2026-12-16'], $this->dates('Victory Day'));
    }

    public function test_the_holidays_sheet_wins_over_an_earlier_tab(): void
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->setTitle('Notes')->fromArray([['Remember to send this to payroll']]);
        $book->createSheet()->setTitle('holidays')->fromArray([['Date', 'Holiday Name'], ['2026-12-16', 'Victory Day']]);

        $path = tempnam(sys_get_temp_dir(), 'hol').'.xlsx';
        IOFactory::createWriter($book, 'Xlsx')->save($path);

        $this->upload($this->manager(), new UploadedFile($path, 'holidays.xlsx', null, null, true))
            ->assertSessionHas('success', 'Imported 1 holiday.');
    }

    public function test_the_first_sheet_is_used_when_none_is_called_holidays(): void
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->setTitle('Sheet1')->fromArray([['Date', 'Holiday Name'], ['2026-12-16', 'Victory Day']]);
        $book->createSheet()->setTitle('Sheet2')->fromArray([['Date', 'Holiday Name'], ['2026-12-25', 'Christmas Day']]);

        $path = tempnam(sys_get_temp_dir(), 'hol').'.xlsx';
        IOFactory::createWriter($book, 'Xlsx')->save($path);

        $this->upload($this->manager(), new UploadedFile($path, 'holidays.xlsx', null, null, true))
            ->assertSessionHas('success', 'Imported 1 holiday.');
        $this->assertSame(['Victory Day'], CalendarEvent::holidays()->pluck('title')->all());
    }

    public function test_a_csv_renamed_to_xlsx_is_treated_as_unreadable_not_as_a_csv(): void
    {
        $file = UploadedFile::fake()->createWithContent('holidays.xlsx', "Date,Holiday Name\n2026-12-16,Victory Day\n");

        $this->actingAs($this->manager())->post(route('calendar.holidays.import'), ['file' => $file])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'could not be read'));
        $this->assertSame(0, CalendarEvent::count());
    }

    public function test_an_xlsx_saved_with_a_csv_name_still_imports(): void
    {
        $xlsx = $this->sheet([['Date', 'Holiday Name'], [Carbon::parse('2026-12-16'), 'Victory Day']]);
        $renamed = new UploadedFile($xlsx->getRealPath(), 'holidays.csv', null, null, true);

        $this->upload($this->manager(), $renamed)->assertSessionHas('success', 'Imported 1 holiday.');
    }

    // ----- the template ---------------------------------------------------------

    public function test_the_template_is_an_xlsx_with_the_expected_headings_and_a_guide(): void
    {
        $response = $this->actingAs($this->manager())->get(route('calendar.holidays.template'));

        $response->assertOk();
        $this->assertStringContainsString('holidays-import-template.xlsx', $response->headers->get('content-disposition'));
        $this->assertStringContainsString('spreadsheetml.sheet', $response->headers->get('content-type'));

        $copy = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        copy($response->baseResponse->getFile()->getPathname(), $copy);
        $book = IOFactory::load($copy);

        $this->assertSame(['Holidays', 'Instructions'], $book->getSheetNames());

        $sheet = $book->getSheet(0);
        $this->assertSame(['Date', 'Holiday Name', 'End Date', 'Description'], [
            $sheet->getCell('A1')->getValue(), $sheet->getCell('B1')->getValue(),
            $sheet->getCell('C1')->getValue(), $sheet->getCell('D1')->getValue(),
        ]);
        // Two sample rows, marked as samples; nothing else.
        $this->assertSame(['Victory Day', 'Eid ul-Fitr'], [$sheet->getCell('B2')->getValue(), $sheet->getCell('B3')->getValue()]);
        $this->assertStringStartsWith(HolidayImporter::SAMPLE_MARK, (string) $sheet->getCell('D2')->getValue());
        $this->assertStringStartsWith(HolidayImporter::SAMPLE_MARK, (string) $sheet->getCell('D3')->getValue());
        $this->assertSame([[null, null, null, null]], array_unique($sheet->rangeToArray('A4:D1001', null, true, false, false), SORT_REGULAR));
        $this->assertSame('yyyy-mm-dd', $sheet->getStyle('A2')->getNumberFormat()->getFormatCode());

        $this->assertStringContainsString('How to fill in', (string) $book->getSheet(1)->getCell('A1')->getValue());
    }

    public function test_a_filled_in_template_imports_cleanly(): void
    {
        $manager = $this->manager();
        $response = $this->actingAs($manager)->get(route('calendar.holidays.template'));

        $copy = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        copy($response->baseResponse->getFile()->getPathname(), $copy);

        $book = IOFactory::load($copy);
        $sheet = $book->getSheet(0);
        // Typed over both samples; row 2's sample description was left behind.
        $sheet->setCellValue('A2', ExcelDate::PHPToExcel(Carbon::parse('2026-03-26')));
        $sheet->setCellValue('B2', 'Independence Day');
        $sheet->setCellValue('A3', ExcelDate::PHPToExcel(Carbon::parse('2026-03-20')));
        $sheet->setCellValue('B3', 'Eid ul-Fitr');
        $sheet->setCellValue('C3', ExcelDate::PHPToExcel(Carbon::parse('2026-03-22')));
        $sheet->setCellValue('D3', 'Three days');
        IOFactory::createWriter($book, 'Xlsx')->save($copy);

        $this->upload($manager, new UploadedFile($copy, 'filled.xlsx', null, null, true))
            ->assertSessionHas('success', 'Imported 2 holidays.');

        $this->assertSame(['2026-03-20', '2026-03-21', '2026-03-22'], $this->dates('Eid ul-Fitr'));
        $this->assertNull(CalendarEvent::where('title', 'Independence Day')->value('description')); // the sample text is not saved
    }

    public function test_the_blank_template_itself_imports_nothing(): void
    {
        $manager = $this->manager();
        $response = $this->actingAs($manager)->get(route('calendar.holidays.template'));

        $copy = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        copy($response->baseResponse->getFile()->getPathname(), $copy);

        $this->upload($manager, new UploadedFile($copy, 'template.xlsx', null, null, true))
            ->assertSessionHas('error', "The file only has the template's sample rows. Type your own holidays over them and upload it again.");
        $this->assertSame(0, CalendarEvent::count());
    }

    // ----- importer rules ----------------------------------------------------------

    /** @return array<string, array{0: mixed}> */
    public static function validDates(): array
    {
        return [
            'iso' => ['2026-12-16'],
            'iso with spaces' => ['  2026-12-16  '],
            'iso with time' => ['2026-12-16 00:00:00'],
            'iso T time' => ['2026-12-16T00:00:00'],
            'slashes, day first' => ['16/12/2026'],
            'dashes, day first' => ['16-12-2026'],
            'dots, day first' => ['16.12.2026'],
            'no leading zero' => ['16/12/2026'],
            'short month' => ['16 Dec 2026'],
            'long month' => ['16 December 2026'],
            'dash month' => ['16-Dec-2026'],
            'american long' => ['December 16, 2026'],
            'american short' => ['Dec 16, 2026'],
            'year first slashes' => ['2026/12/16'],
            'datetime object' => [new \DateTimeImmutable('2026-12-16 15:00')],
            'excel serial' => [ExcelDate::PHPToExcel(new \DateTimeImmutable('2026-12-16'))],
        ];
    }

    #[DataProvider('validDates')]
    public function test_dates_are_understood_in_the_common_spellings(mixed $value): void
    {
        $result = (new HolidayImporter)->import([['Date', 'Holiday Name'], [$value, 'Victory Day']], User::factory()->create()->id);

        $this->assertSame([], $result['errors']);
        $this->assertSame('2026-12-16', $result['created'][0]['start_date']);
    }

    public function test_slash_dates_are_read_day_first(): void
    {
        $result = (new HolidayImporter)->import([['Date', 'Holiday Name'], ['03/04/2026', 'Third of April']], User::factory()->create()->id);

        $this->assertSame('2026-04-03', $result['created'][0]['start_date']);
    }

    /** @return array<string, array{0: mixed}> */
    public static function invalidDates(): array
    {
        return [
            'impossible day' => ['31/02/2026'],
            'month 13' => ['2026-13-01'],
            'words' => ['tomorrow'],
            'too early' => ['1999-12-31'],
            'too late' => ['2101-01-01'],
            'number that is not a date serial' => [20261216],
            'bare year' => [2026.5],
            'text' => ['abc'],
            'boolean' => [true],
        ];
    }

    #[DataProvider('invalidDates')]
    public function test_impossible_dates_are_reported_not_imported(mixed $value): void
    {
        $result = (new HolidayImporter)->import([['Date', 'Holiday Name'], [$value, 'Whatever']], User::factory()->create()->id);

        $this->assertSame([], $result['created']);
        $this->assertSame(2, $result['errors'][0]['row']);
        $this->assertStringContainsString('not a valid date', $result['errors'][0]['message']);
    }

    public function test_names_are_tidied_and_row_numbers_follow_the_sheet(): void
    {
        $rows = [
            ['Date', 'Holiday Name'],                   // row 1
            [],                                         // row 2 (blank)
            ['2026-12-16', "  Victory \n  Day\t"],      // row 3
            ['2026-12-17', str_repeat('a', 256)],       // row 4 — too long
        ];

        $result = (new HolidayImporter)->import($rows, User::factory()->create()->id);

        $this->assertSame('Victory Day', $result['created'][0]['title']);
        $this->assertSame(3, $result['created'][0]['row']);
        $this->assertSame(4, $result['errors'][0]['row']);
        $this->assertSame(2, $result['total']);
    }

    public function test_a_failure_while_saving_rolls_the_whole_import_back(): void
    {
        $user = User::factory()->create();
        $rows = [['Date', 'Holiday Name'], ['2026-01-01', 'One'], ['2026-01-02', 'Two']];

        // Make the second insert blow up.
        $count = 0;
        CalendarEvent::creating(function () use (&$count) {
            if (++$count === 2) {
                throw new \RuntimeException('disk full');
            }
        });

        try {
            (new HolidayImporter)->import($rows, $user->id);
            $this->fail('Expected the import to fail.');
        } catch (\RuntimeException $e) {
            $this->assertSame('disk full', $e->getMessage());
        }

        $this->assertSame(0, CalendarEvent::count());
    }
}
