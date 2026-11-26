<?php

namespace Tests\Feature;

use App\Models\Unit;
use App\Models\User;
use App\Support\MasterData\Code;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Every master code is a unique id of numbers and small letters with at least
 * one number. The masters in MasterDataRegistry are covered in MasterDataTest;
 * this covers the shared rule itself, Units (which has its own screen), and the
 * migration that converted codes saved under the old rule.
 */
class MasterDataCodeFormatTest extends TestCase
{
    use RefreshDatabase;

    private function manageUnits(): User
    {
        $role = Role::create(['name' => 'Unit manager '.uniqid()]);
        $role->givePermissionTo(Permission::findOrCreate('manage units'));
        $user = User::factory()->create();
        $user->syncRoles([$role]);

        return $user;
    }

    // ----- the rule -----------------------------------------------------------------

    /** @return array<string, array{0: string, 1: bool}> */
    public static function codes(): array
    {
        return [
            'numbers' => ['101', true],
            'a leading zero' => ['0652', true],
            'letters then numbers' => ['d001', true],
            'numbers then letters' => ['1a', true],
            'a single digit' => ['7', true],
            'no number' => ['abc', false],
            'a capital' => ['D001', false],
            'a dash' => ['d-001', false],
            'an underscore' => ['d_001', false],
            'a dot' => ['d.001', false],
            'a space' => ['d 001', false],
            'nothing' => ['', false],
            'a letter from another script' => ['ক১', false],
            'fifty characters' => [str_repeat('1', 50), true],
            'fifty-one characters' => [str_repeat('1', 51), false],
        ];
    }

    #[DataProvider('codes')]
    public function test_which_codes_are_valid(string $code, bool $valid): void
    {
        $this->assertSame($valid, Code::isValid($code));
    }

    public function test_a_trailing_newline_does_not_sneak_past_the_pattern(): void
    {
        $this->assertFalse(Code::isValid("101\n"));
    }

    public function test_normalise_tidies_what_was_typed_and_leaves_the_rest_to_the_validator(): void
    {
        $this->assertSame('d001', Code::normalise('  D001 '));
        $this->assertSame('101', Code::normalise(101));
        $this->assertSame('101', Code::normalise(101.0));
        $this->assertSame(101.5, Code::normalise(101.5)); // not a code: the validator rejects it
        $this->assertNull(Code::normalise(null));
        $this->assertSame(['a'], Code::normalise(['a']));
    }

    public function test_clean_keeps_only_numbers_and_small_letters(): void
    {
        $this->assertSame('g01', Code::clean('G-01'));
        $this->assertSame('d001', Code::clean(' D_001. '));
        $this->assertSame('', Code::clean('---'));
        $this->assertSame(50, strlen(Code::clean(str_repeat('a1', 40))));
    }

    // ----- Units ---------------------------------------------------------------------

    public function test_a_unit_code_may_have_letters_numbers_and_hyphens_and_keeps_its_capitals(): void
    {
        $user = $this->manageUnits();

        foreach (['F01-KITH', 'F02-PDD', ' F03-CAL ', 'u1001', '2002'] as $good) {
            $this->actingAs($user)->post(route('units.store'), ['unit_code' => $good, 'name' => 'Unit '.trim($good)])
                ->assertSessionHasNoErrors();
        }
        $this->assertSame(['2002', 'F01-KITH', 'F02-PDD', 'F03-CAL', 'u1001'], Unit::orderBy('unit_code')->pluck('unit_code')->all());

        // No number, a stray symbol, a space inside, or a hyphen at an end or doubled.
        foreach (['UNIT', 'F-PDD', 'u_1001', 'u 1', '-F01', 'F01-', 'F01--PDD', 'F01/PDD'] as $bad) {
            $this->actingAs($user)->post(route('units.store'), ['unit_code' => $bad, 'name' => 'Bad'])
                ->assertSessionHasErrors('unit_code');
        }

        // The same code in other letters is the same code.
        $this->actingAs($user)->post(route('units.store'), ['unit_code' => 'f02-pdd', 'name' => 'Duplicate'])
            ->assertSessionHasErrors('unit_code');
        $this->assertSame(5, Unit::count());
    }

    public function test_a_unit_keeps_its_own_code_when_edited_and_cannot_take_another_units(): void
    {
        $user = $this->manageUnits();
        $one = Unit::create(['unit_code' => '101', 'name' => 'One']);
        Unit::create(['unit_code' => '102', 'name' => 'Two']);

        $this->actingAs($user)->put(route('units.update', $one), ['unit_code' => '101', 'name' => 'One renamed'])
            ->assertSessionHasNoErrors();
        $this->actingAs($user)->put(route('units.update', $one), ['unit_code' => '102', 'name' => 'One'])
            ->assertSessionHasErrors('unit_code');
        $this->actingAs($user)->put(route('units.update', $one), ['unit_code' => 'Unit_1', 'name' => 'One'])
            ->assertSessionHasErrors('unit_code');
        $this->actingAs($user)->put(route('units.update', $one), ['unit_code' => 'F02-PDD', 'name' => 'One renamed'])
            ->assertSessionHasNoErrors();

        $this->assertSame('One renamed', $one->fresh()->name);
        $this->assertSame('F02-PDD', $one->fresh()->unit_code);
    }

    public function test_a_units_file_imported_as_it_is_accepts_codes_like_f02_pdd_reads_numbers_and_skips_bad_ones(): void
    {
        $csv = "unit_code,short_form,name,location,contact_person,phone_number,email,is_active\n"
            ."F01-KITH,KITH,Kith,Dhaka,,,,1\n"
            ."F02-PDD,PDD,Pdd,,,,,1\n"
            ."F03-CAL,CAL,Cal,,,,,1\n"
            ."F04-DFL,DFL,Dfl,,,,,1\n"
            ."F05-CTAL,CTAL,Ctal,,,,,1\n"
            ."F06-VBL,VBL,Vbl,,,,,1\n"
            ."F07-CCML,CCML,Ccml,,,,,1\n"
            ."2002,CTG,Chittagong,,,,,1\n";

        $this->actingAs($this->manageUnits())
            ->post(route('units.import'), ['file' => UploadedFile::fake()->createWithContent('units.csv', $csv)])
            ->assertSessionHas('success');

        $this->assertSame(
            ['2002', 'F01-KITH', 'F02-PDD', 'F03-CAL', 'F04-DFL', 'F05-CTAL', 'F06-VBL', 'F07-CCML'],
            Unit::orderBy('unit_code')->pluck('unit_code')->all()
        );

        // Bad codes are skipped; f02-pdd is F02-PDD again (codes ignore the case of letters), so it updates that unit.
        $bad = "unit_code,name\nUNIT_1,Bad\nF08-,Bad too\nf02-pdd,Same code\n";
        $this->actingAs($this->manageUnits())
            ->post(route('units.import'), ['file' => UploadedFile::fake()->createWithContent('bad.csv', $bad)])
            ->assertSessionHas('import_result', fn ($r) => $r['error_count'] === 2 && $r['updated_count'] === 1 && $r['created_count'] === 0);
        $this->assertSame(8, Unit::count());
        $this->assertSame('Same code', Unit::whereRaw('LOWER(unit_code) = ?', ['f02-pdd'])->value('name'));
    }

    // ----- the migration that converted codes saved under the old rule ----------------

    private function convert(): void
    {
        (require database_path('migrations/2026_11_21_000003_make_master_data_codes_small_letters_and_numbers.php'))->up();
    }

    public function test_old_codes_are_converted_in_every_master_table(): void
    {
        DB::table('departments')->insert(['name' => 'Human Resources', 'short_name' => 'HR', 'code' => 'D001', 'is_active' => true]);
        DB::table('designations')->insert(['designation_category_id' => $this->designationCategory()->id, 'code' => 'SR-EXEC', 'title' => 'Senior Executive', 'serial' => 1, 'is_active' => true]);
        DB::table('leave_types')->insert(['code' => 'CL', 'name' => 'Casual Leave']);
        DB::table('offence_types')->insert(['code' => 'THEFT', 'name' => 'Theft', 'severity' => 'gross']);
        DB::table('document_types')->insert(['code' => 'NATIONAL_ID', 'name' => 'National ID', 'module' => 'employee_profile']);
        DB::table('grades')->insert(['code' => 'G-01', 'name' => 'Grade 1', 'basic_min' => 1, 'basic_max' => 2]);
        DB::table('units')->insert(['unit_code' => 'U000101', 'name' => 'Head Office']);

        $this->convert();

        $this->assertSame('d001', DB::table('departments')->value('code'));
        $this->assertSame('srexec1', DB::table('designations')->value('code')); // no number: one is added
        $this->assertSame('cl1', DB::table('leave_types')->value('code'));
        $this->assertSame('theft1', DB::table('offence_types')->value('code'));
        $this->assertSame('nationalid1', DB::table('document_types')->value('code'));
        $this->assertSame('g01', DB::table('grades')->value('code'));
        $this->assertSame('u000101', DB::table('units')->value('unit_code'));
    }

    public function test_codes_that_become_the_same_stay_unique_and_the_first_keeps_its_name(): void
    {
        DB::table('grades')->insert([
            ['code' => 'A-1', 'name' => 'First', 'basic_min' => 1, 'basic_max' => 2],
            ['code' => 'A_1', 'name' => 'Second', 'basic_min' => 1, 'basic_max' => 2],
            ['code' => 'a.1', 'name' => 'Third', 'basic_min' => 1, 'basic_max' => 2],
        ]);

        $this->convert();

        $this->assertSame(
            ['First' => 'a1', 'Second' => 'a12', 'Third' => 'a13'],
            DB::table('grades')->orderBy('id')->pluck('code', 'name')->all(),
        );
    }

    public function test_a_converted_code_may_take_the_place_of_one_that_is_changing_too(): void
    {
        // Units' codes were free text, so "U1" and "u1" could both exist.
        DB::table('units')->insert([
            ['unit_code' => 'U1', 'name' => 'Capital'],
            ['unit_code' => 'u1', 'name' => 'Small'],
        ]);

        $this->convert();

        $this->assertSame(['Capital' => 'u1', 'Small' => 'u12'], DB::table('units')->orderBy('id')->pluck('unit_code', 'name')->all());
    }

    public function test_codes_that_are_already_valid_are_left_alone(): void
    {
        DB::table('departments')->insert(['name' => 'Sewing', 'short_name' => 'SEW', 'code' => '101', 'is_active' => true]);

        $this->convert();

        $this->assertSame('101', DB::table('departments')->value('code'));
    }
}
