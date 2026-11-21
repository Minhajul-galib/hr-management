<?php

namespace Tests\Feature;

use App\Exports\MasterDataExport;
use App\Models\Department;
use App\Models\User;
use App\Support\Holidays\SpreadsheetRows;
use App\Support\MasterData\MasterDataImporter;
use App\Support\MasterData\MasterDataRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Departments are a server-paged master with Excel import/export. The generic
 * access rules every master shares are covered in MasterDataTest.
 */
class DepartmentTest extends TestCase
{
    use RefreshDatabase;

    private const HEADINGS = "Code,Department Name,Short Name,Description,Active\n";

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
        return $this->userWith(['view departments', 'manage departments']);
    }

    private function department(string $code, string $name, string $short = 'X', bool $active = true): Department
    {
        return Department::create(['code' => $code, 'name' => $name, 'short_name' => $short, 'is_active' => $active]);
    }

    private function csv(string $body, string $name = 'departments.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $body);
    }

    private function import(User $user, UploadedFile $file)
    {
        return $this->actingAs($user)->post(route('departments.import'), ['file' => $file]);
    }

    // ----- list ---------------------------------------------------------------

    public function test_the_list_is_paged_on_the_server(): void
    {
        foreach (range(1, 30) as $i) {
            $this->department(sprintf('d%03d', $i), "Department {$i}");
        }

        $this->actingAs($this->manager())->get(route('departments.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('MasterData/Index')
                ->where('definition.paginate', true)
                ->has('records', 25)
                ->where('records.0.code', 'd001')
                ->where('pagination.total', 30)
                ->where('pagination.last_page', 2)
                ->where('stats.total', 30));

        $this->actingAs($this->manager())->get(route('departments.index', ['page' => 2]))
            ->assertInertia(fn (Assert $page) => $page->has('records', 5)->where('records.0.code', 'd026'));

        $this->actingAs($this->manager())->get(route('departments.index', ['per_page' => 50]))
            ->assertInertia(fn (Assert $page) => $page->has('records', 30)->where('pagination.per_page', 50));
    }

    public function test_an_unsupported_page_size_falls_back_to_the_default(): void
    {
        $this->actingAs($this->manager())->get(route('departments.index', ['per_page' => 100000]))
            ->assertInertia(fn (Assert $page) => $page->where('pagination.per_page', 25));
    }

    public function test_a_page_past_the_end_goes_to_the_last_page(): void
    {
        $this->department('d001', 'Accounts');

        $this->actingAs($this->manager())->get(route('departments.index', ['page' => 9]))
            ->assertRedirect(route('departments.index', ['page' => 1]));
    }

    public function test_search_matches_code_name_or_short_name_ignoring_case(): void
    {
        $this->department('d001', 'Human Resources', 'HR');
        $this->department('d002', 'Accounts Department', 'ACC');
        $this->department('d003', 'Sewing', 'SEW');

        $search = fn (string $q) => $this->actingAs($this->manager())->get(route('departments.index', ['search' => $q]));

        $search('human')->assertInertia(fn (Assert $p) => $p->has('records', 1)->where('records.0.code', 'd001'));
        $search('acc')->assertInertia(fn (Assert $p) => $p->has('records', 1)->where('records.0.code', 'd002'));
        $search('d003')->assertInertia(fn (Assert $p) => $p->has('records', 1)->where('records.0.code', 'd003'));
        $search('accounts dep')->assertInertia(fn (Assert $p) => $p->has('records', 1));
        $search('nothing')->assertInertia(fn (Assert $p) => $p->has('records', 0)->where('stats.total', 3));
    }

    public function test_search_wildcards_are_taken_literally(): void
    {
        $this->department('d001', 'Human Resources');

        $this->actingAs($this->manager())->get(route('departments.index', ['search' => '%']))
            ->assertInertia(fn (Assert $p) => $p->has('records', 0));
    }

    public function test_status_filter(): void
    {
        $this->department('d001', 'Open', active: true);
        $this->department('d002', 'Closed', active: false);

        $this->actingAs($this->manager())->get(route('departments.index', ['status' => 'inactive']))
            ->assertInertia(fn (Assert $p) => $p->has('records', 1)->where('records.0.code', 'd002')->where('stats.active', 1));
    }

    // ----- form ---------------------------------------------------------------

    public function test_a_department_is_created_with_code_and_short_name_in_capitals(): void
    {
        $this->actingAs($this->manager())
            ->post(route('departments.store'), ['code' => 'd010', 'name' => 'Logistics', 'short_name' => 'log', 'is_active' => true])
            ->assertRedirect(route('departments.index'));

        $this->assertDatabaseHas('departments', ['code' => 'd010', 'name' => 'Logistics', 'short_name' => 'LOG']);
    }

    public function test_short_name_and_code_are_required(): void
    {
        $this->actingAs($this->manager())
            ->post(route('departments.store'), ['name' => 'Logistics', 'short_name' => '', 'code' => ''])
            ->assertSessionHasErrors(['short_name', 'code']);
    }

    public function test_names_are_unique_regardless_of_case(): void
    {
        $this->department('d001', 'Human Resources');

        $this->actingAs($this->manager())
            ->post(route('departments.store'), ['code' => 'd002', 'name' => 'human resources', 'short_name' => 'HR'])
            ->assertSessionHasErrors('name');
    }

    public function test_a_department_can_keep_its_own_name_and_code_when_edited(): void
    {
        $department = $this->department('d001', 'Human Resources', 'HR');

        $this->actingAs($this->manager())
            ->put(route('departments.update', $department), ['code' => 'd001', 'name' => 'Human Resources', 'short_name' => 'HRD', 'is_active' => true])
            ->assertSessionHasNoErrors();

        $this->assertSame('HRD', $department->fresh()->short_name);
    }

    public function test_changes_return_to_the_same_search_and_page(): void
    {
        $department = $this->department('d001', 'Human Resources', 'HR');

        $this->actingAs($this->manager())
            ->put(route('departments.update', ['id' => $department->id, 'search' => 'hum', 'page' => 2]), [
                'code' => 'd001', 'name' => 'Human Resources', 'short_name' => 'HR', 'is_active' => true,
            ])
            ->assertRedirect(route('departments.index', ['search' => 'hum', 'page' => 2]));
    }

    public function test_a_department_with_users_cannot_be_deleted(): void
    {
        $department = $this->department('d001', 'Human Resources');
        User::factory()->create(['department_id' => $department->id]);

        $this->actingAs($this->manager())->delete(route('departments.destroy', $department))
            ->assertSessionHas('error');

        $this->assertModelExists($department);
    }

    // ----- access -------------------------------------------------------------

    public function test_viewers_can_list_and_export_but_not_import(): void
    {
        $viewer = $this->userWith(['view departments']);

        $this->actingAs($viewer)->get(route('departments.index'))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('canManage', false));
        $this->actingAs($viewer)->get(route('departments.export'))->assertOk();
        $this->actingAs($viewer)->get(route('departments.template'))->assertForbidden();
        $this->import($viewer, $this->csv(self::HEADINGS."d001,Sales,SAL,,Yes\n"))->assertForbidden();

        $this->assertDatabaseCount('departments', 0);
    }

    public function test_the_hr_roles_get_department_permissions_from_the_registry(): void
    {
        $this->assertContains('view departments', MasterDataRegistry::viewPermissionNames());
        $this->assertContains('manage departments', MasterDataRegistry::permissionNames());
    }

    // ----- import -------------------------------------------------------------

    public function test_import_adds_new_codes_and_updates_existing_ones(): void
    {
        $this->department('d001', 'Human Resources', 'HR');

        $this->import($this->manager(), $this->csv(self::HEADINGS
            ."d001,Human Resources & Admin,hra,People team,Yes\n"
            ."d101,Sewing,SEW,,Yes\n"
            ."d102,Cutting,CUT,,No\n"))
            ->assertRedirect(route('departments.index'))
            ->assertSessionHas('success')
            ->assertSessionHas('import_result', fn ($r) => $r['created_count'] === 2 && $r['updated_count'] === 1 && $r['error_count'] === 0);

        $this->assertDatabaseHas('departments', ['code' => 'd001', 'name' => 'Human Resources & Admin', 'short_name' => 'HRA', 'description' => 'People team']);
        $this->assertDatabaseHas('departments', ['code' => 'd101', 'name' => 'Sewing', 'is_active' => true]);
        $this->assertDatabaseHas('departments', ['code' => 'd102', 'is_active' => false]);
    }

    public function test_reimporting_the_same_file_changes_nothing(): void
    {
        $file = self::HEADINGS."d101,Sewing,SEW,,Yes\n";
        $manager = $this->manager();

        $this->import($manager, $this->csv($file));
        $this->import($manager, $this->csv($file))
            ->assertSessionHas('import_result', fn ($r) => $r['created_count'] === 0 && $r['updated_count'] === 0 && $r['unchanged_count'] === 1);

        $this->assertDatabaseCount('departments', 1);
    }

    public function test_empty_optional_cells_keep_saved_values(): void
    {
        Department::create(['code' => 'd001', 'name' => 'Sewing', 'short_name' => 'SEW', 'description' => 'Floor 2', 'is_active' => false]);

        $this->import($this->manager(), $this->csv(self::HEADINGS."d001,Sewing Section,SEW,,\n"));

        $this->assertDatabaseHas('departments', ['code' => 'd001', 'name' => 'Sewing Section', 'description' => 'Floor 2', 'is_active' => false]);
    }

    public function test_bad_rows_are_reported_by_row_number_and_good_rows_still_saved(): void
    {
        $this->department('d001', 'Human Resources', 'HR');

        $this->import($this->manager(), $this->csv(self::HEADINGS
            ."d101,Sewing,SEW,,Yes\n"            // row 2: fine
            .",No Code,NC,,Yes\n"                // row 3: code missing
            ."d101,Sewing Again,SW2,,Yes\n"      // row 4: same code as row 2
            ."d102,human resources,HR2,,Yes\n"   // row 5: name of d001
            ."d103,Cutting,,,Yes\n"              // row 6: short name missing
            ."d104,Finishing,FIN,,Maybe\n"       // row 7: not yes/no
            ."BAD CODE,Washing,WSH,,Yes\n"))     // row 8: space in code
            ->assertSessionHas('import_result', function ($r) {
                $rows = array_column($r['errors'], 'row');
                sort($rows);

                return $r['created_count'] === 1 && $r['error_count'] === 6 && $rows === [3, 4, 5, 6, 7, 8];
            });

        $this->assertDatabaseHas('departments', ['code' => 'd101', 'name' => 'Sewing']);
        $this->assertDatabaseCount('departments', 2);
    }

    public function test_a_name_repeated_within_the_file_is_caught(): void
    {
        $this->import($this->manager(), $this->csv(self::HEADINGS."d101,Sewing,SEW,,Yes\nD102,SEWING,SW,,Yes\n"))
            ->assertSessionHas('import_result', fn ($r) => $r['created_count'] === 1 && $r['errors'][0]['row'] === 3);
    }

    public function test_a_sheet_without_the_headings_is_refused(): void
    {
        $this->import($this->manager(), $this->csv("d101,Sewing,SEW\n"))
            ->assertSessionHas('error')
            ->assertSessionHas('import_result', fn ($r) => str_contains($r['fatal'], 'column headings'));

        $this->assertDatabaseCount('departments', 0);
    }

    public function test_a_sheet_missing_a_required_column_is_refused(): void
    {
        $this->import($this->manager(), $this->csv("Code,Department Name\nD101,Sewing\n"))
            ->assertSessionHas('import_result', fn ($r) => str_contains($r['fatal'], '"Short Name"'));

        $this->assertDatabaseCount('departments', 0);
    }

    public function test_headings_may_use_common_alternative_names_in_any_order(): void
    {
        $this->import($this->manager(), $this->csv("Status,Dept Code,Short Form,Department\nActive,d101,sew,Sewing\n"));

        $this->assertDatabaseHas('departments', ['code' => 'd101', 'name' => 'Sewing', 'short_name' => 'SEW', 'is_active' => true]);
    }

    public function test_too_many_rows_are_refused_before_anything_is_saved(): void
    {
        $rows = [['Code', 'Department Name', 'Short Name']];
        foreach (range(1, MasterDataImporter::MAX_ROWS + 1) as $i) {
            $rows[] = ["D{$i}", "Department {$i}", 'X'];
        }

        $result = (new MasterDataImporter(MasterDataRegistry::find('departments')))->import($rows);

        $this->assertStringContainsString('more than', $result['fatal']);
        $this->assertDatabaseCount('departments', 0);
    }

    public function test_a_non_spreadsheet_upload_is_rejected(): void
    {
        $this->import($this->manager(), UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'))
            ->assertSessionHasErrors('file');
    }

    // ----- template / export --------------------------------------------------

    public function test_the_template_downloads_as_excel(): void
    {
        $this->actingAs($this->manager())->get(route('departments.template'))
            ->assertOk()
            ->assertDownload('departments-import-template.xlsx');
    }

    public function test_an_exported_file_imports_back_unchanged(): void
    {
        $this->department('d001', 'Human Resources', 'HR');
        $this->department('d002', 'Sewing', 'SEW', active: false);

        $bytes = Excel::raw(new MasterDataExport(MasterDataRegistry::find('departments'), Department::orderBy('code')->get()), ExcelFormat::XLSX);
        $file = UploadedFile::fake()->createWithContent('departments.xlsx', $bytes);

        $this->import($this->manager(), $file)
            ->assertSessionHas('import_result', fn ($r) => $r['total'] === 2 && $r['unchanged_count'] === 2 && $r['error_count'] === 0);
    }

    public function test_export_follows_the_search(): void
    {
        $this->department('d001', 'Human Resources', 'HR');
        $this->department('d002', 'Sewing', 'SEW');

        $response = $this->actingAs($this->manager())->get(route('departments.export', ['search' => 'sew']));
        $response->assertOk();

        $rows = SpreadsheetRows::read($response->baseResponse->getFile()->getPathname(), 'xlsx', 'Departments');

        $this->assertSame('Code', $rows[0][0]);
        $this->assertSame('d002', $rows[1][0]);
        $this->assertNull($rows[2][0] ?? null);
    }
}
