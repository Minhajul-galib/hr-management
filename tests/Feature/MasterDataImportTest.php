<?php

namespace Tests\Feature;

use App\Exports\MasterDataExport;
use App\Models\Department;
use App\Models\Grade;
use App\Models\LeaveType;
use App\Models\SalaryType;
use App\Models\User;
use App\Support\Holidays\SpreadsheetRows;
use App\Support\MasterData\MasterDataImporter;
use App\Support\MasterData\MasterDataRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The Excel import shared by every master: the template's sample rows, the
 * review step (preview / check, which save nothing), code suggestions, imports
 * by another master's code, and the unique indexes the codes rely on.
 * Department-specific import rules are in DepartmentTest.
 */
class MasterDataImportTest extends TestCase
{
    use RefreshDatabase;

    private const DEPT_HEADINGS = "Code,Department Name,Short Name,Description,Active\n";

    private function manager(string $permission): User
    {
        $role = Role::create(['name' => 'Role '.uniqid()]);
        $role->givePermissionTo([Permission::findOrCreate("view {$permission}"), Permission::findOrCreate("manage {$permission}")]);

        $user = User::factory()->create();
        $user->syncRoles([$role]);

        return $user;
    }

    private function department(string $code, string $name, string $short = 'X'): Department
    {
        return Department::create(['code' => $code, 'name' => $name, 'short_name' => $short, 'is_active' => true]);
    }

    private function salaryType(string $code, string $name, float $value = 10, string $calculation = 'percent_of_basic'): SalaryType
    {
        return SalaryType::create(['code' => $code, 'name' => $name, 'calculation' => $calculation, 'value' => $value]);
    }

    private function csv(string $body): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('upload.csv', $body);
    }

    private function preview(string $type, string $permission, string $body)
    {
        return $this->actingAs($this->manager($permission))
            ->postJson(route("{$type}.import.preview"), ['file' => $this->csv($body)]);
    }

    /** The checked row with this sheet row number. */
    private function row(array $json, int $number): array
    {
        return collect($json['rows'])->firstWhere('row', $number) ?? $this->fail("No row {$number} in the preview.");
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function importableMasters(): array
    {
        $masters = [];
        foreach (MasterDataRegistry::all() as $key => $definition) {
            if (isset($definition['import'])) {
                $masters[$key] = [$key, $definition['permission']];
            }
        }

        return $masters;
    }

    // ----- which masters import ---------------------------------------------------

    public function test_every_master_that_fits_one_row_per_record_has_an_import(): void
    {
        $this->assertSame(
            ['departments', 'designations', 'salary-types', 'grades', 'leave-types', 'offence-types', 'document-types'],
            array_keys(self::importableMasters()),
        );
        // Criteria and rating bands are lists, which a single sheet row can't hold.
        $this->assertArrayNotHasKey('import', MasterDataRegistry::find('acr-templates'));
    }

    // ----- template sample rows ------------------------------------------------------

    #[DataProvider('importableMasters')]
    public function test_the_template_has_two_sample_rows_that_are_skipped_on_import(string $type, string $permission): void
    {
        $definition = MasterDataRegistry::find($type);
        $this->assertCount(2, $definition['import']['examples'], "{$type} needs two sample rows");

        $manager = $this->manager($permission);
        $response = $this->actingAs($manager)->get(route("{$type}.template"))->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();

        $rows = SpreadsheetRows::read($path, 'xlsx', $definition['import']['sheet']);
        $headings = array_map(fn ($f) => MasterDataImporter::heading($f), MasterDataImporter::columns($definition));
        $this->assertSame($headings, array_slice($rows[0], 0, count($headings)));
        $this->assertNotNull($rows[1][0], 'sample row 1');
        $this->assertNotNull($rows[2][0], 'sample row 2');
        $this->assertNull($rows[3][0] ?? null);

        // Uploading the untouched template imports nothing.
        $count = $definition['model']::count();
        $file = UploadedFile::fake()->createWithContent("{$type}.xlsx", file_get_contents($path));
        $this->actingAs($manager)->post(route("{$type}.import"), ['file' => $file])
            ->assertSessionHas('error')
            ->assertSessionHas('import_result', fn ($r) => $r['sample_count'] === 2 && $r['created_count'] === 0 && $r['error_count'] === 0);
        $this->assertSame($count, $definition['model']::count());
    }

    #[DataProvider('importableMasters')]
    public function test_the_sample_rows_pass_every_check_once_they_are_edited(string $type, string $permission): void
    {
        // The grade samples list salary type codes 120 and 652; they must exist for the row to pass.
        $this->salaryType('120', 'Fixture A');
        $this->salaryType('652', 'Fixture B');

        $definition = MasterDataRegistry::find($type);
        $columns = MasterDataImporter::columns($definition);

        // The samples as the sheet shows them, with the code changed so they are real rows.
        $records = [];
        foreach ($definition['import']['examples'] as $i => $example) {
            $cells = [];
            foreach ($columns as $field) {
                $cells[$field['key']] = (string) MasterDataExport::cell($field, $example[$field['key']] ?? '');
            }
            $cells['code'] .= 'X';
            $records[] = ['row' => $i + 2, 'cells' => $cells];
        }

        $result = (new MasterDataImporter($definition))->check($records);

        foreach ($result['rows'] as $row) {
            $this->assertSame('new', $row['status'], "{$type} sample row {$row['row']}: ".json_encode($row['errors'] + $row['row_errors']));
        }
    }

    public function test_a_row_like_a_sample_is_imported_when_its_code_is_already_saved(): void
    {
        LeaveType::create(['code' => '401', 'name' => 'Casual Leave', 'entitlement_days' => 8, 'accrual' => 'yearly']);

        // Exactly sample row 1 of the leave types template, but 401 is a real, saved leave type.
        $this->actingAs($this->manager('leave types'))
            ->post(route('leave-types.import'), ['file' => $this->csv("Code,Name,Paid leave,Entitlement (days per year),Accrual,Unused days carry forward,Max carry-forward days,Supporting attachment required,Active\n"
                ."401,Casual Leave,Yes,10,Yearly (credited upfront),No,,No,Yes\n")])
            ->assertSessionHas('import_result', fn ($r) => $r['sample_count'] === 0 && $r['updated_count'] === 1);

        $this->assertSame('10.0', LeaveType::firstWhere('code', '401')->entitlement_days);
    }

    public function test_a_reviewed_row_is_never_taken_for_a_sample(): void
    {
        $sample = ['code' => '101', 'name' => 'Human Resources', 'short_name' => 'HR', 'description' => 'Recruitment, payroll and employee relations', 'is_active' => 'Yes'];

        $this->actingAs($this->manager('departments'))
            ->postJson(route('departments.import.check'), ['rows' => [
                ['row' => 2, 'cells' => $sample, 'sample' => true],  // still the template's sample
                ['row' => 3, 'cells' => $sample, 'sample' => false], // "import it anyway" on the review screen
            ]])
            ->assertJsonPath('rows.0.status', 'sample')
            ->assertJsonPath('rows.1.status', 'new');
    }

    public function test_an_edited_sample_row_is_imported(): void
    {
        $this->actingAs($this->manager('departments'))
            ->post(route('departments.import'), ['file' => $this->csv(self::DEPT_HEADINGS
                ."101,Human Resources,HR,\"Recruitment, payroll and employee relations\",Yes\n" // sample, untouched
                ."102,Merchandising,MER,Knit and woven buyers,Yes\n")])                        // sample, edited
            ->assertSessionHas('import_result', fn ($r) => $r['sample_count'] === 1 && $r['created_count'] === 1);

        $this->assertDatabaseMissing('departments', ['code' => '101']);
        $this->assertDatabaseHas('departments', ['code' => '102', 'description' => 'Knit and woven buyers']);
    }

    // ----- review: preview and check ----------------------------------------------------

    public function test_preview_reports_each_row_and_saves_nothing(): void
    {
        $this->department('d001', 'Human Resources', 'HR');

        $json = $this->preview('departments', 'departments', self::DEPT_HEADINGS
            ."d001,Human Resources,HR,,\n"          // row 2: same as saved
            ."d001,Human Resources,HRA,,\n"         // row 3: same code again
            ."d005,Sewing,sew,,\n"                  // row 4: new
            .",Cutting,CUT,,Yes\n")                 // row 5: no code
            ->assertOk()
            ->assertJsonPath('file', 'upload.csv')
            ->json();

        $this->assertSame('unchanged', $this->row($json, 2)['status']);
        $this->assertSame('error', $this->row($json, 3)['status']);
        $this->assertStringContainsString('already used on row 2', $this->row($json, 3)['errors']['code']);

        $new = $this->row($json, 4);
        $this->assertSame('new', $new['status']);
        $this->assertSame(['d005', 'SEW'], [$new['cells']['code'], $new['cells']['short_name']]);
        $this->assertSame('Yes', $new['fill']['is_active']); // empty cell → the default
        $this->assertSame('default', $new['fill_kind']);

        $this->assertSame('Code is missing.', $this->row($json, 5)['errors']['code']);
        $this->assertSame(['total' => 4, 'new' => 1, 'update' => 0, 'unchanged' => 1, 'error' => 2], array_intersect_key($json['summary'], array_flip(['total', 'new', 'update', 'unchanged', 'error'])));

        $this->assertDatabaseCount('departments', 1);
    }

    public function test_preview_shows_what_an_update_changes_and_what_it_keeps(): void
    {
        Department::create(['code' => 'd001', 'name' => 'Sewing', 'short_name' => 'SEW', 'description' => 'Floor 2', 'is_active' => true]);

        $row = $this->row($this->preview('departments', 'departments', self::DEPT_HEADINGS."d001,Sewing,SW,,No\n")->json(), 2);

        $this->assertSame('update', $row['status']);
        $this->assertSame(['short_name' => 'SEW', 'is_active' => 'Yes'], $row['was']);
        $this->assertSame(['description' => 'Floor 2'], $row['fill']);
        $this->assertSame('kept', $row['fill_kind']);
    }

    public function test_a_repeated_code_gets_the_next_free_code_in_its_series(): void
    {
        foreach (['d001', 'd002', 'd010'] as $i => $code) {
            $this->department($code, "Existing {$i}");
        }

        $json = $this->preview('departments', 'departments', self::DEPT_HEADINGS
            ."d011,Sewing,SEW,,\n"
            ."d011,Cutting,CUT,,\n"     // repeats row 2
            ."d011,Finishing,FIN,,\n"   // and again: must not get the same suggestion
            .",Washing,WSH,,\n"         // no code: continues the series too
            ."bad code,Printing,PRT,,\n")->json();

        $this->assertSame('d012', $this->row($json, 3)['suggestions']['code']);
        $this->assertSame('d013', $this->row($json, 4)['suggestions']['code']);
        $this->assertSame('d014', $this->row($json, 5)['suggestions']['code']);
        $this->assertSame('badcode1', $this->row($json, 6)['suggestions']['code']); // no number: one is added
    }

    public function test_capitals_in_a_code_are_made_small_and_other_mistakes_get_a_clean_suggestion(): void
    {
        $json = $this->preview('departments', 'departments', self::DEPT_HEADINGS
            ."D017,Sewing,SEW,,\n"        // capitals: fixed on the way in
            ."d-018,Cutting,CUT,,\n"      // a dash
            ."10 1,Finishing,FIN,,\n"     // a space
            ."hr,People,HR,,\n")->json(); // no number

        $capitals = $this->row($json, 2);
        $this->assertSame('new', $capitals['status']);
        $this->assertSame('d017', $capitals['cells']['code']);
        $this->assertArrayNotHasKey('code', $capitals['suggestions']);

        $this->assertSame('error', $this->row($json, 3)['status']);
        $this->assertSame('d018', $this->row($json, 3)['suggestions']['code']);
        $this->assertStringContainsString('numbers and small letters', $this->row($json, 3)['errors']['code']);
        $this->assertSame('101', $this->row($json, 4)['suggestions']['code']);
        $this->assertSame('hr1', $this->row($json, 5)['suggestions']['code']);
    }

    public function test_a_code_without_a_number_continues_a_numbered_series(): void
    {
        $this->department('hr1', 'Human Resources');
        $this->department('hr2', 'Human Resources 2');

        $json = $this->preview('departments', 'departments', self::DEPT_HEADINGS."hr,People,HR,,\nadm,Admin,ADM,,\n")->json();

        $this->assertSame('hr3', $this->row($json, 2)['suggestions']['code']);
        $this->assertSame('adm1', $this->row($json, 3)['suggestions']['code']);
    }

    public function test_codes_typed_as_numbers_in_excel_are_read_as_text(): void
    {
        $result = (new MasterDataImporter(MasterDataRegistry::find('departments')))->import([
            ['Code', 'Department Name', 'Short Name'],
            [101, 'Sewing', 'SEW'],      // a numeric cell
            [102.0, 'Cutting', 'CUT'],   // Excel hands whole numbers back as floats
            ['0103', 'Finishing', 'FIN'], // text keeps its leading zero
        ]);

        $this->assertSame(3, $result['created']);
        $this->assertSame(['0103', '101', '102'], Department::orderBy('code')->pluck('code')->all());
    }

    public function test_renaming_a_saved_code_is_flagged_with_a_free_code_in_case_it_was_meant_as_new(): void
    {
        $this->department('d004', 'Purchase Department', 'PUR');

        $row = $this->row($this->preview('departments', 'departments', self::DEPT_HEADINGS."d004,Sewing,SEW,,\n")->json(), 2);

        $this->assertSame('update', $row['status']); // still allowed: renaming is a normal edit
        $this->assertStringContainsString('already belongs to "Purchase Department"', $row['warnings']['code']);
        $this->assertSame('d005', $row['suggestions']['code']);
    }

    public function test_a_short_name_used_elsewhere_is_a_warning_not_an_error(): void
    {
        $this->department('d101', 'Sewing', 'SEW');

        $json = $this->preview('departments', 'departments', self::DEPT_HEADINGS."d102,Sewing Line 2,SEW,,\nD103,Sample Room,SR,,\nD104,Sample Section,SR,,\n")->json();

        $this->assertSame('new', $this->row($json, 2)['status']);
        $this->assertStringContainsString('d101 "Sewing"', $this->row($json, 2)['warnings']['short_name']);
        $this->assertStringContainsString('on row 4', $this->row($json, 3)['warnings']['short_name']);
        $this->assertStringContainsString('on row 3', $this->row($json, 4)['warnings']['short_name']);
    }

    public function test_a_near_miss_choice_is_suggested(): void
    {
        $row = $this->row($this->preview('leave-types', 'leave types', "Code,Name,Entitlement (days per year),Accrual\nSL,Sick Leave,14,Montly\n")->json(), 2);

        $this->assertSame('error', $row['status']);
        $this->assertSame('Monthly (1/12 each month)', $row['suggestions']['accrual']);
    }

    public function test_check_takes_edited_rows_and_saves_nothing(): void
    {
        $this->department('d001', 'Human Resources');

        $this->actingAs($this->manager('departments'))
            ->postJson(route('departments.import.check'), ['rows' => [
                ['row' => 2, 'cells' => ['code' => 'd001', 'name' => 'Sewing', 'short_name' => 'SEW']],
                ['row' => 3, 'cells' => ['code' => 'd002', 'name' => 'Sewing', 'short_name' => 'SW']],
                ['row' => 4, 'cells' => ['code' => 'd003', 'name' => 'Cutting', 'short_name' => 'CUT'], 'sample' => true],
            ]])
            ->assertOk()
            ->assertJsonPath('rows.0.status', 'update')
            ->assertJsonPath('rows.1.status', 'error')
            ->assertJsonPath('rows.1.errors.name', 'Department Name "Sewing" is already used on row 2 of this file.')
            ->assertJsonPath('rows.2.status', 'sample');

        $this->assertDatabaseHas('departments', ['code' => 'd001', 'name' => 'Human Resources']);
        $this->assertDatabaseCount('departments', 1);
    }

    public function test_the_reviewed_rows_are_imported_and_problem_rows_skipped(): void
    {
        $this->actingAs($this->manager('departments'))
            ->post(route('departments.import'), ['file_name' => 'depts.xlsx', 'rows' => [
                ['row' => 2, 'cells' => ['code' => 'd101', 'name' => 'Sewing', 'short_name' => 'sew', 'is_active' => 'Yes']],
                ['row' => 3, 'cells' => ['code' => 'd102', 'name' => '', 'short_name' => 'CUT']],
                ['row' => 4, 'cells' => ['code' => 'd103', 'name' => 'Sample', 'short_name' => 'SMP'], 'sample' => true],
            ]])
            ->assertRedirect(route('departments.index'))
            ->assertSessionHas('success')
            ->assertSessionHas('import_result', fn ($r) => $r['file'] === 'depts.xlsx'
                && $r['created_count'] === 1 && $r['sample_count'] === 1 && $r['error_count'] === 1 && $r['errors'][0]['row'] === 3);

        $this->assertDatabaseHas('departments', ['code' => 'd101', 'short_name' => 'SEW']);
        $this->assertDatabaseCount('departments', 1);
    }

    public function test_a_file_without_headings_is_refused_by_the_preview(): void
    {
        $this->preview('departments', 'departments', "d101,Sewing,SEW\n")
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'column headings'));
    }

    public function test_viewers_cannot_preview_or_check(): void
    {
        $role = Role::create(['name' => 'Viewer']);
        $role->givePermissionTo(Permission::findOrCreate('view departments'));
        $viewer = User::factory()->create();
        $viewer->syncRoles([$role]);

        $this->actingAs($viewer)->postJson(route('departments.import.preview'), ['file' => $this->csv(self::DEPT_HEADINGS)])->assertForbidden();
        $this->actingAs($viewer)->postJson(route('departments.import.check'), ['rows' => []])->assertForbidden();
    }

    public function test_too_many_reviewed_rows_are_refused(): void
    {
        $rows = array_fill(0, MasterDataImporter::MAX_ROWS + 1, ['row' => 2, 'cells' => ['code' => 'X']]);

        $this->actingAs($this->manager('departments'))
            ->postJson(route('departments.import.check'), ['rows' => $rows])
            ->assertStatus(422)
            ->assertJsonValidationErrors('rows');
    }

    // ----- a master that refers to another by code ----------------------------------------

    public function test_grades_are_imported_with_their_salary_type_codes(): void
    {
        $rent = $this->salaryType('120', 'House Rent', 50);
        $conveyance = $this->salaryType('652', 'Conveyance', 10);
        $existing = Grade::create(['code' => 'g1', 'name' => 'Grade 1', 'basic_min' => 100, 'basic_max' => 200]);
        $existing->salaryTypes()->attach($rent);

        $this->actingAs($this->manager('grades'))
            ->post(route('grades.import'), ['file' => $this->csv("Code,Name,Basic Salary (Min),Basic Salary (Max),Salary Type Codes\n"
                .'g1,Grade One,150,250,"120, 652"'."\n"   // updates g1 and adds a salary type
                ."g2,Grade Two,90,95,652\n"                 // new
                ."g3,Grade Three,1,2,\n"                    // new, no salary types
                ."g4,Grade Four,1,2,999\n")])               // no such salary type
            ->assertSessionHas('import_result', fn ($r) => $r['created_count'] === 2 && $r['updated_count'] === 1 && $r['error_count'] === 1 && $r['errors'][0]['row'] === 5);

        $this->assertSame('Grade One', $existing->fresh()->name);
        $this->assertEqualsCanonicalizing([$rent->id, $conveyance->id], $existing->salaryTypes()->pluck('salary_types.id')->all());
        $this->assertSame([$conveyance->id], Grade::firstWhere('code', 'g2')->salaryTypes()->pluck('salary_types.id')->all());
        $this->assertSame(0, Grade::firstWhere('code', 'g3')->salaryTypes()->count());
        $this->assertNull(Grade::firstWhere('code', 'g4'));
    }

    public function test_an_empty_salary_types_cell_keeps_what_is_saved(): void
    {
        $rent = $this->salaryType('120', 'House Rent', 50);
        $grade = Grade::create(['code' => 'g1', 'name' => 'Grade 1', 'basic_min' => 100, 'basic_max' => 200]);
        $grade->salaryTypes()->attach($rent);

        $this->actingAs($this->manager('grades'))
            ->post(route('grades.import'), ['file' => $this->csv("Code,Name,Basic Salary (Min),Basic Salary (Max),Salary Type Codes\nG1,Grade One,100,200,\n")])
            ->assertSessionHas('import_result', fn ($r) => $r['updated_count'] === 1);

        $this->assertSame([$rent->id], $grade->salaryTypes()->pluck('salary_types.id')->all());
    }

    public function test_changing_only_a_grades_salary_types_counts_as_an_update(): void
    {
        $rent = $this->salaryType('120', 'House Rent', 50);
        $this->salaryType('652', 'Conveyance', 10);
        $grade = Grade::create(['code' => 'g1', 'name' => 'Grade 1', 'basic_min' => 100, 'basic_max' => 200]);
        $grade->salaryTypes()->attach($rent);

        $headings = "Code,Name,Basic Salary (Min),Basic Salary (Max),Salary Type Codes\n";
        $changed = $this->row($this->preview('grades', 'grades', $headings."g1,Grade 1,100,200,\"652, 120\"\n")->json(), 2);
        $this->assertSame('update', $changed['status']);
        $this->assertSame(['salary_type_ids' => '120'], $changed['was']);
        $this->assertSame('652, 120', $changed['cells']['salary_type_ids']); // kept in the order it was typed

        // The same codes are not a change, however they are spaced or repeated.
        $same = $this->row($this->preview('grades', 'grades', $headings."g1,Grade 1,100,200,\" 120 , 120 \"\n")->json(), 2);
        $this->assertSame('unchanged', $same['status']);
        $this->assertSame('120', $same['cells']['salary_type_ids']);
    }

    public function test_unknown_salary_type_codes_are_reported_and_a_near_miss_is_suggested(): void
    {
        $this->salaryType('120', 'House Rent', 50);
        $this->salaryType('652', 'Conveyance', 10);

        $row = $this->row($this->preview('grades', 'grades', "Code,Name,Basic Salary (Min),Basic Salary (Max),Salary Type Codes\nG1,Grade 1,1,2,\"120, 65\"\n")->json(), 2);

        $this->assertSame('error', $row['status']);
        $this->assertStringContainsString('"65" was not found', $row['errors']['salary_type_ids']);
        $this->assertSame('120, 652', $row['suggestions']['salary_type_ids']);
    }

    public function test_salary_type_codes_that_are_not_close_to_any_get_no_suggestion(): void
    {
        $this->salaryType('120', 'House Rent', 50);

        $row = $this->row($this->preview('grades', 'grades', "Code,Name,Basic Salary (Min),Basic Salary (Max),Salary Type Codes\nG1,Grade 1,1,2,ZZZZZZ\n")->json(), 2);

        $this->assertSame('error', $row['status']);
        $this->assertArrayNotHasKey('salary_type_ids', $row['suggestions']);
    }

    public function test_grades_with_no_salary_types_on_file_say_to_add_them_first(): void
    {
        $row = $this->row($this->preview('grades', 'grades', "Code,Name,Basic Salary (Min),Basic Salary (Max),Salary Type Codes\nG1,Grade 1,1,2,120\n")->json(), 2);

        $this->assertStringContainsString('Add them on the Salary Types page first', $row['errors']['salary_type_ids']);
    }

    public function test_an_inactive_salary_type_is_a_warning(): void
    {
        SalaryType::create(['code' => '120', 'name' => 'Old', 'calculation' => 'fixed', 'value' => 5, 'is_active' => false]);

        $row = $this->row($this->preview('grades', 'grades', "Code,Name,Basic Salary (Min),Basic Salary (Max),Salary Type Codes\nG1,Grade 1,1,2,120\n")->json(), 2);

        $this->assertSame('new', $row['status']);
        $this->assertSame('120 is inactive.', $row['warnings']['salary_type_ids']);
    }

    public function test_exported_grades_carry_their_salary_type_codes_and_import_back_unchanged(): void
    {
        $rent = $this->salaryType('120', 'House Rent', 50);
        $conveyance = $this->salaryType('652', 'Conveyance', 10);
        $grade = Grade::create(['code' => 'g1', 'name' => 'Grade 1', 'basic_min' => 100, 'basic_max' => 200.5]);
        $grade->salaryTypes()->attach([$conveyance->id, $rent->id]);

        $manager = $this->manager('grades');
        $response = $this->actingAs($manager)->get(route('grades.export'))->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();
        $rows = SpreadsheetRows::read($path, 'xlsx', 'Grades');

        $this->assertSame(['Code', 'Name', 'Basic Salary (Min)', 'Basic Salary (Max)', 'Salary Type Codes', 'Active'], array_slice($rows[0], 0, 6));
        $this->assertEquals(['g1', 'Grade 1', 100, 200.5, '120, 652', 'Yes'], array_slice($rows[1], 0, 6)); // 100 may come back as 100.0

        $this->actingAs($manager)
            ->post(route('grades.import'), ['file' => UploadedFile::fake()->createWithContent('grades.xlsx', file_get_contents($path))])
            ->assertSessionHas('import_result', fn ($r) => $r['total'] === 1 && $r['unchanged_count'] === 1 && $r['error_count'] === 0);
    }

    public function test_salary_types_import_with_a_type_column_and_the_percentage_in_the_value(): void
    {
        $this->actingAs($this->manager('salary types'))
            ->post(route('salary-types.import'), ['file' => $this->csv("Code,Salary Type,Type,Percentage\n"
                ."120,House Rent,% of basic salary,45\n"
                ."652,Medical,Fixed amount,750\n"
                ."700,Bonus,percent_of_basic,150\n")]) // over 100%
            ->assertSessionHas('import_result', fn ($r) => $r['created_count'] === 2 && $r['error_count'] === 1 && $r['errors'][0]['row'] === 4);

        $this->assertDatabaseHas('salary_types', ['code' => '120', 'name' => 'House Rent', 'calculation' => 'percent_of_basic', 'value' => 45]);
        $this->assertDatabaseHas('salary_types', ['code' => '652', 'calculation' => 'fixed', 'value' => 750]);
        $this->assertDatabaseMissing('salary_types', ['code' => '700']);
    }

    public function test_numeric_salary_type_codes_continue_their_series(): void
    {
        $this->salaryType('120', 'House Rent');
        $this->salaryType('121', 'Medical');

        $json = $this->preview('salary-types', 'salary types', "Code,Name,Calculation,Value\n"
            ."122,Conveyance,% of basic salary,10\n"
            ."122,Food,Fixed amount,900\n"        // repeats row 2
            ."120,Rent again,% of basic salary,5\n")->json(); // saved code, renamed

        $this->assertSame('new', $this->row($json, 2)['status']);
        $this->assertSame('123', $this->row($json, 3)['suggestions']['code']);
        $this->assertStringContainsString('already belongs to "House Rent"', $this->row($json, 4)['warnings']['code']);
        $this->assertSame('124', $this->row($json, 4)['suggestions']['code']);
    }

    public function test_a_salary_type_name_repeated_in_the_file_is_caught(): void
    {
        $json = $this->preview('salary-types', 'salary types', "Code,Name,Calculation,Value\n130,House Rent,% of basic salary,45\n131,house rent,% of basic salary,40\n")->json();

        $this->assertSame('error', $this->row($json, 3)['status']);
        $this->assertStringContainsString('already used on row 2', $this->row($json, 3)['errors']['name']);
    }

    public function test_an_exported_leave_type_file_imports_back_unchanged(): void
    {
        LeaveType::create(['code' => '401', 'name' => 'Casual Leave', 'entitlement_days' => 10, 'accrual' => 'yearly']);
        LeaveType::create(['code' => '402', 'name' => 'Earned Leave', 'entitlement_days' => 14.5, 'accrual' => 'monthly', 'carry_forward' => true, 'carry_forward_limit_days' => 40, 'is_paid' => true]);

        $definition = MasterDataRegistry::find('leave-types');
        $bytes = Excel::raw(new MasterDataExport($definition, LeaveType::orderBy('code')->get()), ExcelFormat::XLSX);

        $this->actingAs($this->manager('leave types'))
            ->post(route('leave-types.import'), ['file' => UploadedFile::fake()->createWithContent('leave.xlsx', $bytes)])
            ->assertSessionHas('import_result', fn ($r) => $r['total'] === 2 && $r['unchanged_count'] === 2 && $r['error_count'] === 0);
    }

    // ----- schema --------------------------------------------------------------------

    public function test_existing_document_types_are_given_a_code_made_from_their_name(): void
    {
        $migration = require database_path('migrations/2026_11_21_000001_add_code_to_document_types_table.php');
        $migration->down();

        DB::table('document_types')->insert([
            ['name' => 'National ID', 'module' => 'employee_profile', 'is_mandatory' => true, 'is_active' => true],
            ['name' => 'National ID', 'module' => 'recruitment', 'is_mandatory' => true, 'is_active' => true],
            ['name' => 'Offer letter (signed)', 'module' => 'recruitment', 'is_mandatory' => false, 'is_active' => true],
        ]);

        $migration->up();

        $this->assertSame(['NATIONAL_ID', 'NATIONAL_ID_2', 'OFFER_LETTER_SIGNED'], DB::table('document_types')->orderBy('id')->pluck('code')->all());
    }

    /**
     * Codes are what other records will link to, so each master's identifying
     * columns — and every `unique` set — must have a unique index in the
     * database, not just a validation rule.
     */
    public function test_every_identifying_column_set_has_a_unique_index(): void
    {
        foreach (MasterDataRegistry::all() as $key => $definition) {
            $table = (new $definition['model'])->getTable();
            $sets = [isset($definition['import']) ? MasterDataImporter::matchKeys($definition) : ['code']];
            foreach ($definition['unique'] ?? [] as $set) {
                $sets[] = $set;
            }

            $indexes = array_map(
                fn ($index) => collect($index['columns'])->sort()->values()->all(),
                array_filter(Schema::getIndexes($table), fn ($index) => $index['unique']),
            );

            foreach ($sets as $set) {
                sort($set);
                $this->assertContains($set, $indexes, "{$key}: no unique index on (".implode(', ', $set).')');
            }
        }
    }
}
