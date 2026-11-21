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

    public function test_a_unit_code_follows_the_same_rule_and_is_saved_in_small_letters(): void
    {
        $user = $this->manageUnits();

        $this->actingAs($user)->post(route('units.store'), ['unit_code' => ' U1001 ', 'name' => 'Factory One'])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('units', ['unit_code' => 'u1001']);

        foreach (['UNIT', 'u-1001', 'u 1', 'unit_1'] as $bad) {
            $this->actingAs($user)->post(route('units.store'), ['unit_code' => $bad, 'name' => 'Bad'])
                ->assertSessionHasErrors('unit_code');
        }

        // The same code in capitals is the same code.
        $this->actingAs($user)->post(route('units.store'), ['unit_code' => 'U1001', 'name' => 'Duplicate'])
            ->assertSessionHasErrors('unit_code');
        $this->assertSame(1, Unit::count());
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
        $this->actingAs($user)->put(route('units.update', $one), ['unit_code' => 'Unit-1', 'name' => 'One'])
            ->assertSessionHasErrors('unit_code');

        $this->assertSame('One renamed', $one->fresh()->name);
    }

    public function test_units_import_lowercases_codes_reads_numbers_and_rejects_bad_ones(): void
    {
        $csv = "unit_code,short_form,name,location,contact_person,phone_number,email,is_active\n"
            ."U2001,HO,Head Office,Dhaka,,,,1\n"
            ."2002,CTG,Chittagong,,,,,1\n";

        $this->actingAs($this->manageUnits())
            ->post(route('units.import'), ['file' => UploadedFile::fake()->createWithContent('units.csv', $csv)])
            ->assertSessionHas('success');

        $this->assertSame(['2002', 'u2001'], Unit::orderBy('unit_code')->pluck('unit_code')->all());

        $bad = "unit_code,name\nUNIT-1,Bad\n";
        $this->actingAs($this->manageUnits())
            ->post(route('units.import'), ['file' => UploadedFile::fake()->createWithContent('bad.csv', $bad)])
            ->assertSessionHas('error');
        $this->assertSame(2, Unit::count());
    }

    public function test_the_units_template_shows_numeric_codes(): void
    {
        $response = $this->actingAs($this->manageUnits())->get(route('units.template'))->assertOk();

        $this->assertStringContainsString("\n101,HO,", $response->streamedContent());
    }

    // ----- the migration that converted codes saved under the old rule ----------------

    private function convert(): void
    {
        (require database_path('migrations/2026_11_21_000003_make_master_data_codes_small_letters_and_numbers.php'))->up();
    }

    public function test_old_codes_are_converted_in_every_master_table(): void
    {
        DB::table('departments')->insert(['name' => 'Human Resources', 'short_name' => 'HR', 'code' => 'D001', 'is_active' => true]);
        DB::table('designations')->insert(['code' => 'SR-EXEC', 'title' => 'Senior Executive', 'serial' => 1, 'is_active' => true]);
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
