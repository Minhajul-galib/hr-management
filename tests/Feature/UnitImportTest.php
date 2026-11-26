<?php

namespace Tests\Feature;

use App\Models\Unit;
use App\Models\User;
use App\Support\Holidays\SpreadsheetRows;
use App\Support\MasterData\MasterDataImporter;
use App\Support\MasterData\MasterDataRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Units import Excel the way every master does: a template with two sample rows,
 * an upload that is reviewed (preview / check save nothing) and only then saved.
 * Units keep their own screen, so the same engine is reached by /units/import/*.
 * A unit's code looks like F02-PDD and an import is part of adding units.
 */
class UnitImportTest extends TestCase
{
    use RefreshDatabase;

    private const HEADINGS = "Unit Code,Short Form,Unit Name,Location,Contact Person,Phone Number,Email,Active\n";

    /** The codes the user listed. */
    private const FACTORIES = [
        'F01-KITH' => 'Kith', 'F02-PDD' => 'Pdd', 'F03-CAL' => 'Cal', 'F04-DFL' => 'Dfl',
        'F05-CTAL' => 'Ctal', 'F06-VBL' => 'Vbl', 'F07-CCML' => 'Ccml',
    ];

    private function userWith(string ...$permissions): User
    {
        $role = Role::create(['name' => 'Role '.uniqid()]);
        $role->givePermissionTo(array_map(fn ($p) => Permission::findOrCreate($p), $permissions));
        $user = User::factory()->create();
        $user->syncRoles([$role]);

        return $user;
    }

    private function csv(string $body): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('units.csv', $body);
    }

    private function factoriesCsv(): string
    {
        $body = self::HEADINGS;
        foreach (self::FACTORIES as $code => $name) {
            $body .= "{$code},".strtoupper($name).",{$name} Factory,,,,,Yes\n";
        }

        return $body;
    }

    private function preview(string $body, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->userWith('create units'))
            ->postJson(route('units.import.preview'), ['file' => $this->csv($body)]);
    }

    private function row(array $json, int $number): array
    {
        return collect($json['rows'])->firstWhere('row', $number) ?? $this->fail("No row {$number} in the preview.");
    }

    // ----- template ------------------------------------------------------------------

    public function test_the_template_has_two_sample_rows_that_are_skipped_on_import(): void
    {
        $definition = MasterDataRegistry::find('units');
        $this->assertCount(2, $definition['import']['examples']);

        $user = $this->userWith('create units');
        $path = $this->actingAs($user)->get(route('units.template'))->assertOk()->baseResponse->getFile()->getPathname();

        $rows = SpreadsheetRows::read($path, 'xlsx', 'Units');
        $headings = array_map(fn ($f) => MasterDataImporter::heading($f), MasterDataImporter::columns($definition));
        $this->assertSame($headings, array_slice($rows[0], 0, count($headings)));
        $this->assertSame('F01-HO', $rows[1][0]);
        $this->assertNotNull($rows[2][0]);
        $this->assertNull($rows[3][0] ?? null);

        $file = UploadedFile::fake()->createWithContent('units.xlsx', file_get_contents($path));
        $this->actingAs($user)->post(route('units.import'), ['file' => $file])
            ->assertSessionHas('error')
            ->assertSessionHas('import_result', fn ($r) => $r['sample_count'] === 2 && $r['created_count'] === 0 && $r['error_count'] === 0);
        $this->assertSame(0, Unit::count());
    }

    // ----- review, then import ---------------------------------------------------------

    public function test_the_codes_like_f02_pdd_are_reviewed_then_saved_with_their_capitals(): void
    {
        $user = $this->userWith('create units');

        $json = $this->preview($this->factoriesCsv(), $user)->assertOk()->json();
        $this->assertSame(7, $json['summary']['new']);
        $this->assertSame(0, $json['summary']['error']);
        $this->assertSame(0, Unit::count(), 'the preview saves nothing');

        $rows = array_map(fn ($row) => ['row' => $row['row'], 'cells' => $row['cells'], 'sample' => $row['sample']], $json['rows']);
        $this->actingAs($user)->post(route('units.import'), ['rows' => $rows, 'file_name' => 'units.csv'])
            ->assertSessionHas('success')
            ->assertSessionHas('import_result', fn ($r) => $r['created_count'] === 7 && $r['error_count'] === 0);

        $this->assertSame(array_keys(self::FACTORIES), Unit::orderBy('unit_code')->pluck('unit_code')->all());
        $unit = Unit::where('unit_code', 'F02-PDD')->first();
        $this->assertSame('PDD', $unit->short_form);
        $this->assertTrue($unit->is_active);
        $this->assertSame($user->id, $unit->created_by, 'an imported unit is credited to whoever imported it');
    }

    public function test_a_row_edited_on_the_review_screen_is_checked_again(): void
    {
        $user = $this->userWith('create units');

        $json = $this->actingAs($user)->postJson(route('units.import.check'), ['rows' => [
            ['row' => 2, 'cells' => ['unit_code' => 'F02_PDD', 'name' => 'Pdd'], 'sample' => false],
            ['row' => 3, 'cells' => ['unit_code' => 'F02-PDD', 'name' => 'Pdd'], 'sample' => false],
        ]])->assertOk()->json();

        $this->assertSame('error', $this->row($json, 2)['status']);
        $this->assertSame('new', $this->row($json, 3)['status']);
        $this->assertSame(0, Unit::count());
    }

    public function test_a_bad_code_is_explained_and_a_valid_one_is_suggested(): void
    {
        Unit::create(['unit_code' => 'F03-CAL', 'name' => 'Cal']);

        $json = $this->preview(self::HEADINGS
            ."F02_PDD,,Underscore,,,,,\n"
            ."HQ,,No number,,,,,\n"
            ."F03-CAL,,Taken,,,,,\n"
            ."f03-cal,,Same again,,,,,\n"
            ."F01-,,Trailing hyphen,,,,,\n")->assertOk()->json();

        $underscore = $this->row($json, 2);
        $this->assertStringContainsString('letters, numbers and hyphens', $underscore['errors']['unit_code']);
        $this->assertSame('F02-PDD', $underscore['suggestions']['unit_code']);

        $this->assertSame('HQ1', $this->row($json, 3)['suggestions']['unit_code']);

        // F03-CAL is saved, so row 4 updates that unit; "f03-cal" on row 5 is the same code again, in this file.
        $this->assertSame('update', $this->row($json, 4)['status']);
        $this->assertStringContainsString('already used', $this->row($json, 5)['errors']['unit_code']);

        $this->assertSame('F01', $this->row($json, 6)['suggestions']['unit_code']);
    }

    public function test_a_code_matches_a_saved_unit_whatever_the_case_of_its_letters(): void
    {
        $saved = Unit::create(['unit_code' => 'F02-PDD', 'name' => 'Old name', 'location' => 'Dhaka']);

        $json = $this->preview(self::HEADINGS."f02-pdd,,New name,,,,,\n")->assertOk()->json();

        $row = $this->row($json, 2);
        $this->assertSame('update', $row['status']);
        $this->assertSame('Old name', $row['was']['name']);
        $this->assertSame('F02-PDD', $row['was']['unit_code'], 'the review shows the code would be respelled as typed');
        $this->assertSame('Dhaka', $row['fill']['location'], 'a blank cell keeps what is saved');

        $this->actingAs($this->userWith('create units'))->post(route('units.import'), ['file' => $this->csv(self::HEADINGS."f02-pdd,,New name,,,,,\n")]);
        $this->assertSame(1, Unit::count());
        $this->assertSame('New name', $saved->fresh()->name);
        $this->assertSame('f02-pdd', $saved->fresh()->unit_code, 'the letters are kept as typed, so capitals can be put back on a lowercased code');
    }

    public function test_a_code_held_by_a_unit_in_the_trash_is_refused_with_a_reason(): void
    {
        Unit::create(['unit_code' => 'F09-OLD', 'name' => 'Old'])->delete();

        $row = $this->row($this->preview(self::HEADINGS."F09-OLD,,Again,,,,,\n")->assertOk()->json(), 2);

        $this->assertSame('error', $row['status']);
        $this->assertStringContainsString('Trash', $row['errors']['unit_code']);
    }

    public function test_the_other_columns_follow_the_unit_rules(): void
    {
        $row = $this->row($this->preview(self::HEADINGS."F01-HO,,Head Office,,,,not-an-email,maybe\n")->assertOk()->json(), 2);

        $this->assertSame('error', $row['status']);
        $this->assertArrayHasKey('email', $row['errors']);
        $this->assertArrayHasKey('is_active', $row['errors']);
    }

    // ----- export ----------------------------------------------------------------------

    public function test_the_list_exports_in_the_import_layout_and_reads_back_unchanged(): void
    {
        Unit::create(['unit_code' => 'F02-PDD', 'name' => 'Pdd Factory', 'short_form' => 'PDD', 'phone_number' => '01712345678']);
        Unit::create(['unit_code' => 'F01-OLD', 'name' => 'Old'])->delete();

        $user = $this->userWith('view units');
        $path = $this->actingAs($user)->get(route('units.export'))->assertOk()->baseResponse->getFile()->getPathname();

        // The sheet's column is formatted for 2,000 rows, so skip the empty ones.
        $rows = array_values(array_filter(SpreadsheetRows::read($path, 'xlsx', 'Units'), fn ($row) => ($row[0] ?? null) !== null && $row[0] !== ''));
        $this->assertCount(2, $rows, 'a heading row and the one unit that is not in the Trash');
        $this->assertSame(['F02-PDD', 'PDD', 'Pdd Factory'], array_slice($rows[1], 0, 3));
        $this->assertSame('01712345678', (string) $rows[1][5]);

        // Re-uploading the export changes nothing (importing needs create units).
        $file = UploadedFile::fake()->createWithContent('units.xlsx', file_get_contents($path));
        $this->actingAs($this->userWith('create units'))->post(route('units.import'), ['file' => $file])
            ->assertSessionHas('import_result', fn ($r) => $r['unchanged_count'] === 1 && $r['created_count'] === 0 && $r['updated_count'] === 0);
    }

    public function test_exporting_needs_view_units(): void
    {
        $this->actingAs($this->userWith('create units'))->get(route('units.export'))->assertForbidden();
        $this->actingAs($this->userWith('manage units'))->get(route('units.export'))->assertOk();
    }

    // ----- permissions and the page ------------------------------------------------------

    public function test_the_import_is_part_of_adding_units(): void
    {
        $rows = [['row' => 2, 'cells' => ['unit_code' => 'F01-HO', 'name' => 'Head Office'], 'sample' => false]];

        foreach (['view units', 'edit units', 'delete units'] as $permission) {
            $user = $this->userWith($permission);
            $this->actingAs($user)->get(route('units.template'))->assertForbidden();
            $this->actingAs($user)->postJson(route('units.import.preview'), ['file' => $this->csv(self::HEADINGS."F01-HO,,Head Office,,,,,\n")])->assertForbidden();
            $this->actingAs($user)->postJson(route('units.import.check'), ['rows' => $rows])->assertForbidden();
            $this->actingAs($user)->post(route('units.import'), ['rows' => $rows])->assertForbidden();
        }
        $this->assertSame(0, Unit::count());

        foreach (['create units', 'manage units'] as $permission) {
            $this->actingAs($this->userWith($permission))->post(route('units.import'), ['rows' => $rows])->assertRedirect(route('units.index'));
        }
        $this->assertSame(1, Unit::count()); // the second import found F01-HO saved: nothing to change
    }

    public function test_the_units_page_gets_what_the_import_screens_need(): void
    {
        $this->actingAs($this->userWith('create units'))->get(route('units.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Units/Index')
                ->where('importDefinition.key', 'units')
                ->where('importDefinition.label', 'Units')
                ->where('importDefinition.import.match_label', 'Unit Code')
                ->where('importDefinition.import.samples', 2)
                ->has('importDefinition.import.columns', 8)
                ->where('importLimits.max_rows', MasterDataImporter::MAX_ROWS)
                ->where('importResult', null));
    }

    public function test_units_are_not_added_to_the_generic_master_screens(): void
    {
        $this->assertNotContains('units', MasterDataRegistry::keys());
        $this->assertNotContains('import units', MasterDataRegistry::allPermissionNames());
    }
}
