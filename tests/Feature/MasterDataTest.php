<?php

namespace Tests\Feature;

use App\Models\AcrTemplate;
use App\Models\Designation;
use App\Models\DocumentType;
use App\Models\Grade;
use App\Models\LeaveType;
use App\Models\OffenceType;
use App\Models\PayScale;
use App\Models\PayScaleLine;
use App\Models\User;
use App\Support\MasterData\MasterDataRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MasterDataTest extends TestCase
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

    private function manager(string $permission): User
    {
        return $this->userWith(["view {$permission}", "manage {$permission}"]);
    }

    /** @return array<string, array{0: string, 1: string, 2: array<string, mixed>, 3: class-string, 4: array<string, mixed>}> */
    public static function simpleMasters(): array
    {
        return [
            'designations' => ['designations', 'designations', [
                'code' => ' 201A ', 'title' => 'General Manager', 'serial' => 3, 'is_active' => true,
            ], Designation::class, ['code' => '201a', 'title' => 'General Manager']],

            'leave-types' => ['leave-types', 'leave types', [
                'code' => '1001', 'name' => 'Casual Leave', 'is_paid' => true, 'entitlement_days' => 10,
                'accrual' => 'yearly', 'carry_forward' => false, 'attachment_required' => false, 'is_active' => true,
            ], LeaveType::class, ['code' => '1001', 'name' => 'Casual Leave']],

            'offence-types' => ['offence-types', 'offence types', [
                'code' => '501', 'name' => 'Unauthorised absence', 'severity' => 'minor', 'description' => 'Absent without leave.', 'is_active' => true,
            ], OffenceType::class, ['code' => '501', 'name' => 'Unauthorised absence', 'severity' => 'minor']],

            'document-types' => ['document-types', 'document types', [
                'code' => '603', 'name' => 'National ID', 'module' => 'employee_profile', 'is_mandatory' => true, 'is_active' => true,
            ], DocumentType::class, ['code' => '603', 'name' => 'National ID', 'is_mandatory' => true]],
        ];
    }

    // ----- access ------------------------------------------------------------

    public function test_every_master_has_an_index_route_and_permissions(): void
    {
        foreach (MasterDataRegistry::all() as $key => $master) {
            $this->assertTrue(\Illuminate\Support\Facades\Route::has("{$key}.index"), "missing route {$key}.index");
            $this->assertTrue(Permission::where('name', "view {$master['permission']}")->exists());
            $this->assertTrue(Permission::where('name', "manage {$master['permission']}")->exists());
        }
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get(route('designations.index'))->assertRedirect(route('login'));
    }

    public function test_users_without_the_permission_are_forbidden_on_every_master(): void
    {
        $user = $this->userWith(['view dashboard']);

        foreach (MasterDataRegistry::keys() as $key) {
            $this->actingAs($user)->get(route("{$key}.index"))->assertForbidden();
            $this->actingAs($user)->post(route("{$key}.store"), [])->assertForbidden();
        }
    }

    public function test_view_only_users_can_list_but_not_change(): void
    {
        $viewer = $this->userWith(['view designations']);
        $designation = Designation::create(['designation_category_id' => $this->designationCategory()->id, 'code' => '101', 'title' => 'Managing Director', 'serial' => 1]);

        $this->actingAs($viewer)->get(route('designations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('MasterData/Index')
                ->where('definition.key', 'designations')
                ->where('can', ['create' => false, 'edit' => false, 'delete' => false, 'import' => false, 'export' => false])
                ->has('records', 1));

        $this->actingAs($viewer)->post(route('designations.store'), ['designation_category_id' => $this->designationCategory()->id, 'code' => '999', 'title' => 'X', 'serial' => 1])->assertForbidden();
        $this->actingAs($viewer)->put(route('designations.update', $designation), ['designation_category_id' => $this->designationCategory()->id, 'code' => '999', 'title' => 'X', 'serial' => 1])->assertForbidden();
        $this->actingAs($viewer)->delete(route('designations.destroy', $designation))->assertForbidden();

        $this->assertDatabaseHas('designations', ['id' => $designation->id, 'code' => '101']);
    }

    public function test_super_admin_can_use_every_master(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::create(['name' => 'Super System Admin']));

        foreach (MasterDataRegistry::keys() as $key) {
            $this->actingAs($admin)->get(route("{$key}.index"))->assertOk();
        }
    }

    // ----- create / update / delete -----------------------------------------

    #[DataProvider('simpleMasters')]
    public function test_a_manager_can_create_update_and_delete(string $key, string $permission, array $payload, string $model, array $expected): void
    {
        $user = $this->manager($permission);

        if ($key === 'designations') {
            $payload['designation_category_id'] = $this->designationCategory()->id;
        }

        $this->actingAs($user)->post(route("{$key}.store"), $payload)
            ->assertRedirect(route("{$key}.index"))
            ->assertSessionHasNoErrors();

        $record = $model::first();
        $this->assertNotNull($record);
        foreach ($expected as $column => $value) {
            $this->assertEquals($value, $record->{$column}, "{$column} was not stored as expected");
        }

        $changed = array_merge($payload, isset($payload['name']) ? ['name' => 'Renamed'] : ['title' => 'Renamed']);
        $this->actingAs($user)->put(route("{$key}.update", $record), $changed)
            ->assertRedirect(route("{$key}.index"))
            ->assertSessionHasNoErrors();
        $this->assertEquals('Renamed', $model::first()->{isset($payload['name']) ? 'name' : 'title'});

        $this->actingAs($user)->delete(route("{$key}.destroy", $record))->assertRedirect(route("{$key}.index"));
        $this->assertSame(0, $model::count());
    }

    public function test_codes_are_stored_in_small_letters_and_must_be_unique_regardless_of_case(): void
    {
        $user = $this->manager('designations');
        Designation::create(['designation_category_id' => $this->designationCategory()->id, 'code' => '201a', 'title' => 'General Manager', 'serial' => 3]);

        $this->actingAs($user)->post(route('designations.store'), ['designation_category_id' => $this->designationCategory()->id, 'code' => '201A', 'title' => 'Another', 'serial' => 4])
            ->assertSessionHasErrors('code');

        $this->assertSame(1, Designation::count());
    }

    public function test_capitals_and_spaces_around_a_code_are_tidied_before_it_is_saved(): void
    {
        $this->actingAs($this->manager('designations'))
            ->post(route('designations.store'), ['designation_category_id' => $this->designationCategory()->id, 'code' => '  GM101  ', 'title' => 'General Manager', 'serial' => 1])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('designations', ['code' => 'gm101']);
    }

    /** @return array<string, array{0: string}> */
    public static function badCodes(): array
    {
        return [
            'a space' => ['10 1'],
            'a dash' => ['d-001'],
            'an underscore' => ['d_001'],
            'a dot' => ['d.001'],
            'a symbol' => ['101!'],
            'letters only' => ['gm'],
            'letters only, capitals' => ['GM'],
            'too long' => [str_repeat('1', 51)],
        ];
    }

    #[DataProvider('badCodes')]
    public function test_codes_must_be_numbers_and_small_letters_with_at_least_one_number(string $code): void
    {
        $this->actingAs($this->manager('designations'))
            ->post(route('designations.store'), ['designation_category_id' => $this->designationCategory()->id, 'code' => $code, 'title' => 'X', 'serial' => 1])
            ->assertSessionHasErrors('code');

        $this->assertSame(0, Designation::count());
    }

    /** @return array<string, array{0: string}> */
    public static function goodCodes(): array
    {
        return [
            'numbers' => ['101'],
            'a leading zero' => ['0652'],
            'letters and numbers' => ['d001'],
            'a number first' => ['1a'],
            'the longest allowed' => [str_repeat('1', 50)],
        ];
    }

    #[DataProvider('goodCodes')]
    public function test_valid_codes_are_accepted(string $code): void
    {
        $this->actingAs($this->manager('designations'))
            ->post(route('designations.store'), ['designation_category_id' => $this->designationCategory()->id, 'code' => $code, 'title' => 'X', 'serial' => 1])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('designations', ['code' => $code]);
    }

    public function test_every_master_refuses_a_code_with_capitals_symbols_or_no_number(): void
    {
        foreach (MasterDataRegistry::all() as $key => $master) {
            if (! collect($master['fields'])->contains('key', 'code')) {
                continue;
            }
            $this->actingAs($this->manager($master['permission']))
                ->post(route("{$key}.store"), ['code' => 'AB-C'])
                ->assertSessionHasErrors('code');
            $this->actingAs($this->manager($master['permission']))
                ->post(route("{$key}.store"), ['code' => 'abc'])
                ->assertSessionHasErrors('code');
        }
    }

    public function test_updating_a_record_may_keep_its_own_code(): void
    {
        $user = $this->manager('designations');
        $designation = Designation::create(['designation_category_id' => $this->designationCategory()->id, 'code' => '201', 'title' => 'General Manager', 'serial' => 3]);

        $this->actingAs($user)->put(route('designations.update', $designation), ['designation_category_id' => $this->designationCategory()->id, 'code' => '201', 'title' => 'GM Operations', 'serial' => 3])
            ->assertSessionHasNoErrors();

        $this->assertSame('GM Operations', $designation->fresh()->title);
    }

    public function test_update_of_a_missing_record_is_404(): void
    {
        $this->actingAs($this->manager('designations'))
            ->put(route('designations.update', 999), ['designation_category_id' => $this->designationCategory()->id, 'code' => '999', 'title' => 'X', 'serial' => 1])
            ->assertNotFound();
    }

    // ----- salary modules (removed) ------------------------------------------

    public function test_salary_module_is_no_longer_a_master(): void
    {
        // A company's formulas name their own components, so there is no separate list of heads.
        $this->assertNull(MasterDataRegistry::find('salary-types'));
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('salary-types.index'));
        $this->assertFalse(Permission::where('name', 'like', '%salary types')->exists());
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('pay_scale_lines', 'salary_type_id'));
    }

    public function test_grade_types_no_longer_exist(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('grade-types.index'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('grade_types'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('grades', 'grade_type_id'));
        $this->assertFalse(Permission::where('name', 'like', '%grade types')->exists());
    }

    // ----- grades ------------------------------------------------------------

    /** The formula with this name (Group and VBL come with the app). */
    private function formula(string $name): PayScale
    {
        return PayScale::with('lines')->firstWhere('name', $name) ?? $this->fail("No formula called {$name}.");
    }

    private function gradePayload(array $overrides = []): array
    {
        return array_merge(['code' => '400', 'type' => 'group_male', 'pay_scale_id' => $this->formula('Group')->id, 'is_active' => true], $overrides);
    }

    /**
     * Replace a formula's components: [title, formula] pairs, in order. Makes the formula when there
     * is none by that name.
     *
     * @param  list<array{0: string, 1: string}>  $lines
     */
    private function payScale(string $name, array $lines): PayScale
    {
        $scale = PayScale::firstOrCreate(['name' => $name]);
        $scale->lines()->delete();

        foreach (array_values($lines) as $position => [$title, $formula]) {
            PayScaleLine::create(['pay_scale_id' => $scale->id, 'position' => $position, 'title' => $title, 'formula' => $formula]);
        }

        return PayScale::with('lines')->find($scale->id);
    }

    /** What a formula pays a grade, by component title. @return array<string, float|null> */
    private function worked(PayScale $scale, string $grade): array
    {
        $values = $scale->calculate($grade);

        return $scale->lines->mapWithKeys(fn (PayScaleLine $l) => [$l->title => $values[$l->id]])->all();
    }

    public function test_a_manager_can_create_update_and_delete_a_grade(): void
    {
        $user = $this->manager('grades');
        $vbl = $this->formula('VBL');

        $this->actingAs($user)->post(route('grades.store'), $this->gradePayload())->assertSessionHasNoErrors();

        $grade = Grade::first();
        $this->assertSame(['400', 'group_male', $this->formula('Group')->id], [$grade->code, $grade->type, $grade->pay_scale_id]);

        $this->actingAs($user)->put(route('grades.update', $grade), $this->gradePayload(['code' => '410', 'type' => 'vbl_male', 'pay_scale_id' => $vbl->id]))
            ->assertSessionHasNoErrors();
        $this->assertSame(['410', 'vbl_male', $vbl->id], [$grade->fresh()->code, $grade->fresh()->type, $grade->fresh()->pay_scale_id]);

        $this->actingAs($user)->delete(route('grades.destroy', $grade))->assertRedirect(route('grades.index'));
        $this->assertSame(0, Grade::count());
    }

    public function test_a_grade_is_a_code_a_type_and_a_formula(): void
    {
        // A name, salary range or salary types in the request are not part of a grade, and are not saved.
        $this->actingAs($this->manager('grades'))
            ->post(route('grades.store'), $this->gradePayload([
                'type' => 'vbl_female_65', 'salary_type_ids' => [1], 'name' => 'Grade 1', 'basic_min' => 30000, 'basic_max' => 20000,
            ]))
            ->assertSessionHasNoErrors();

        $row = \Illuminate\Support\Facades\DB::table('grades')->first();
        $this->assertSame(['400', 'vbl_female_65', $this->formula('Group')->id], [$row->code, $row->type, $row->pay_scale_id]);
        $this->assertNull($row->name);
        $this->assertNull($row->basic_min);
        $this->assertNull($row->basic_max);
    }

    public function test_a_grade_needs_a_code_a_type_and_a_formula(): void
    {
        $user = $this->manager('grades');
        $group = $this->formula('Group')->id;

        $this->actingAs($user)->post(route('grades.store'), ['code' => '', 'type' => 'group_male', 'pay_scale_id' => $group])->assertSessionHasErrors('code');
        $this->actingAs($user)->post(route('grades.store'), ['code' => '400', 'pay_scale_id' => $group])->assertSessionHasErrors('type');
        $this->actingAs($user)->post(route('grades.store'), ['code' => '400', 'type' => 'contract', 'pay_scale_id' => $group])->assertSessionHasErrors('type');
        $this->actingAs($user)->post(route('grades.store'), ['code' => '400', 'type' => 'group_male'])->assertSessionHasErrors('pay_scale_id');
        $this->actingAs($user)->post(route('grades.store'), ['code' => '400', 'type' => 'group_male', 'pay_scale_id' => 99999])->assertSessionHasErrors('pay_scale_id');
        $this->assertSame(0, Grade::count());

        $this->actingAs($user)->post(route('grades.store'), ['code' => '400', 'type' => 'group_male', 'pay_scale_id' => $group])->assertSessionHasNoErrors();
        $this->assertSame(1, Grade::count());
    }

    public function test_the_formula_is_the_grades_own_choice_whatever_its_type(): void
    {
        $user = $this->manager('grades');

        // A Group - Male grade paid by the VBL formula, and a VBL grade paid by Group's.
        $this->actingAs($user)->post(route('grades.store'), $this->gradePayload(['code' => '400', 'type' => 'group_male', 'pay_scale_id' => $this->formula('VBL')->id]))->assertSessionHasNoErrors();
        $this->actingAs($user)->post(route('grades.store'), $this->gradePayload(['code' => '401', 'type' => 'vbl_female_65', 'pay_scale_id' => $this->formula('Group')->id]))->assertSessionHasNoErrors();

        $this->assertSame('Gross', Grade::firstWhere('code', '400')->calculations()[0]['title']);   // VBL starts from Gross
        $this->assertSame([['title' => 'Basic', 'value' => 10975.0], ['title' => 'House Rent', 'value' => 5487.5], ['title' => 'Conveyance', 'value' => 2000.0], ['title' => 'Gross', 'value' => 18462.5]], Grade::firstWhere('code', '401')->calculations());
    }

    public function test_every_type_of_grade_can_be_saved_and_changed(): void
    {
        $user = $this->manager('grades');

        foreach (['group_male', 'group_female_65', 'vbl_male', 'vbl_female_65'] as $i => $type) {
            $this->actingAs($user)->post(route('grades.store'), $this->gradePayload(['code' => '40'.($i + 1), 'type' => $type]))
                ->assertSessionHasNoErrors();
            $this->assertSame($type, Grade::firstWhere('code', '40'.($i + 1))->type);
        }

        $grade = Grade::firstWhere('code', '401');
        $this->actingAs($user)->put(route('grades.update', $grade), $this->gradePayload(['code' => '401', 'type' => 'vbl_male']))
            ->assertSessionHasNoErrors();
        $this->assertSame('vbl_male', $grade->fresh()->type);
    }

    public function test_a_grade_code_stays_unique_across_types(): void
    {
        Grade::create(['code' => '400', 'type' => Grade::GROUP_MALE, 'pay_scale_id' => $this->formula('Group')->id]);

        $this->actingAs($this->manager('grades'))
            ->post(route('grades.store'), $this->gradePayload(['code' => '400', 'type' => 'vbl_male']))
            ->assertSessionHasErrors('code');
        $this->assertSame(1, Grade::count());
    }

    public function test_a_grades_status_is_optional_and_defaults_to_active(): void
    {
        $user = $this->manager('grades');
        $payload = $this->gradePayload();
        unset($payload['is_active']);

        $this->actingAs($user)->post(route('grades.store'), $payload)->assertSessionHasNoErrors();
        $this->assertTrue(Grade::firstWhere('code', '400')->fresh()->is_active);

        $this->actingAs($user)->post(route('grades.store'), $this->gradePayload(['code' => '410', 'is_active' => false]))->assertSessionHasNoErrors();
        $this->assertFalse(Grade::firstWhere('code', '410')->is_active);
    }

    public function test_grade_codes_are_unique_regardless_of_case(): void
    {
        $flat = $this->payScale('Flat', [['Basic', '9000']]); // no [Grade], so a code with letters is allowed
        Grade::create(['code' => '3a', 'type' => Grade::VBL_MALE, 'pay_scale_id' => $flat->id]);

        $this->actingAs($this->manager('grades'))
            ->post(route('grades.store'), $this->gradePayload(['code' => '3A', 'type' => 'vbl_male', 'pay_scale_id' => $flat->id]))
            ->assertSessionHasErrors(['code' => 'The code has already been taken.']);

        $this->assertSame(1, Grade::count());
    }

    // ----- salary formulas ------------------------------------------------------

    public function test_group_and_vbl_come_with_the_formulas_they_were_given(): void
    {
        $this->assertSame(['Group', 'VBL'], PayScale::orderBy('id')->pluck('name')->all());

        $this->assertSame([
            'Basic' => '[Grade] * 25 + 950', 'House Rent' => '[Basic] / 2', 'Conveyance' => '2000',
            'Gross' => '[Basic] + [House Rent] + [Conveyance]',
        ], $this->formula('Group')->lines->pluck('formula', 'title')->all());
        // 400 x 25 + 950 = 10,950; house rent half of it; Gross adds them up.
        $this->assertSame(['Basic' => 10950.0, 'House Rent' => 5475.0, 'Conveyance' => 2000.0, 'Gross' => 18425.0], $this->worked($this->formula('Group'), '400'));

        // VBL starts from Gross and takes shares of it.
        $this->assertSame(['Gross' => 10950.0, 'Basic' => 6570.0, 'House Rent' => 3285.0, 'Medical' => 1095.0], $this->worked($this->formula('VBL'), '400'));
    }

    public function test_a_component_can_use_others_in_any_order_and_functions(): void
    {
        $scale = $this->payScale('VBL', [
            ['Medical', 'max([Gross] * 10%, 1500)'],     // uses Gross, which comes later
            ['Gross', '[Grade] * 25 + 950'],
            ['Bonus', 'round([Gross] / 3)'],
            ['Net', '[Gross] - [Medical] + -100'],
            ['Flat', '2000'],
        ]);

        $this->assertSame(['Medical' => 1500.0, 'Gross' => 3450.0, 'Bonus' => 1150.0, 'Net' => 1850.0, 'Flat' => 2000.0], $this->worked($scale, '100'));
    }

    public function test_an_amount_that_cannot_be_worked_out_is_blank_and_so_is_whatever_uses_it(): void
    {
        $scale = $this->payScale('Group', [
            ['Basic', '[Grade] * 25 + 950'],
            ['House Rent', '[Basic] / 2'],
            ['Conveyance', '2000'],
            ['Odd', '[Conveyance] / ([Basic] - [Basic])'],   // divides by zero
        ]);

        // "g1" is not a number: anything from [Grade] is blank, a fixed amount still is what it is.
        $this->assertSame(['Basic' => null, 'House Rent' => null, 'Conveyance' => 2000.0, 'Odd' => null], $this->worked($scale, 'g1'));
        $this->assertSame(['Basic' => null, 'House Rent' => null, 'Conveyance' => 2000.0, 'Odd' => null], $this->worked($scale, ''));
        $this->assertSame(['Basic' => 950.0, 'House Rent' => 475.0, 'Conveyance' => 2000.0, 'Odd' => null], $this->worked($scale, '0'));
    }

    public function test_a_loop_that_got_saved_anyway_is_blank_instead_of_running_forever(): void
    {
        $scale = $this->payScale('VBL', [['Basic', '[Gross] * 60%'], ['Gross', '[Basic] + 1'], ['Flat', '5']]);

        $this->assertSame(['Basic' => null, 'Gross' => null, 'Flat' => 5.0], $this->worked($scale, '400'));
    }

    public function test_a_formula_that_uses_the_grade_needs_numeric_grades(): void
    {
        $user = $this->manager('grades');

        $this->actingAs($user)->post(route('grades.store'), $this->gradePayload(['code' => 'g1']))
            ->assertSessionHasErrors(['code' => 'The Group formula uses the grade number, so the grade must be a number such as 400.']);

        // A formula that does not use [Grade] does not care.
        $flat = $this->payScale('Flat', [['Basic', '9000'], ['Gross', '[Basic] * 2']]);
        $this->actingAs($user)->post(route('grades.store'), $this->gradePayload(['code' => 'g1', 'pay_scale_id' => $flat->id]))->assertSessionHasNoErrors();
        $this->assertSame(1, Grade::count());
    }

    public function test_the_grades_page_has_a_formula_column_and_a_column_per_component(): void
    {
        Grade::create(['code' => '400', 'type' => Grade::GROUP_MALE, 'pay_scale_id' => $this->formula('Group')->id]);
        Grade::create(['code' => '410', 'type' => Grade::VBL_FEMALE_65, 'pay_scale_id' => $this->formula('VBL')->id]);
        Grade::create(['code' => '420', 'type' => Grade::VBL_MALE]); // saved before formulas were chosen

        $this->actingAs($this->manager('grades'))->get(route('grades.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('definition.columns', fn ($columns) => array_column($columns->toArray(), 'key') === ['code', 'type', 'pay_scale_id', 'calculations', 'is_active'])
                ->where('definition.fields', fn ($fields) => array_column($fields->toArray(), 'key') === ['code', 'type', 'pay_scale_id', 'is_active'])
                ->where('definition.filters', ['type', 'pay_scale_id'])
                ->where('definition.fields.2.required', true)
                ->where('lookups.pay_scale_id', fn ($options) => array_column($options->toArray(), 'label') === ['Group', 'VBL'] && collect($options)->every(fn ($o) => $o['active'] === true))
                ->where('payScales.titles', fn ($t) => array_column($t->toArray(), 'label') === ['Basic', 'House Rent', 'Conveyance', 'Gross', 'Medical'])
                ->where('records.0.pay_scale_id', $this->formula('Group')->id)
                ->where('records.0.calculations', fn ($c) => $c->toArray() == ['basic' => 10950, 'house rent' => 5475, 'conveyance' => 2000, 'gross' => 18425])
                // 410 x 25 + 950 = 11,200 Gross; Basic 60%, house rent 30%, medical 10%.
                ->where('records.1.calculations', fn ($c) => $c->toArray() == ['gross' => 11200, 'basic' => 6720, 'house rent' => 3360, 'medical' => 1120])
                ->where('records.2.pay_scale_id', null)
                ->where('records.2.calculations', []));
    }

    public function test_existing_grades_were_given_the_formula_their_type_used(): void
    {
        $up = fn () => (require database_path('migrations/2026_11_23_000006_let_each_grade_choose_its_formula.php'));
        $up()->down();
        \Illuminate\Support\Facades\DB::table('grades')->insert([
            ['code' => '400', 'type' => 'group_female_65', 'is_active' => true],
            ['code' => '401', 'type' => 'vbl_male', 'is_active' => true],
            ['code' => '402', 'type' => null, 'is_active' => true],
        ]);
        $up()->up();

        $this->assertSame(
            ['400' => 'Group', '401' => 'VBL', '402' => null],
            Grade::with('payScale')->orderBy('code')->get()->mapWithKeys(fn (Grade $g) => [$g->code => $g->payScale?->name])->all(),
        );
    }

    public function test_the_grades_page_offers_the_four_types_and_can_filter_by_them(): void
    {
        Grade::create(['code' => '400', 'type' => Grade::GROUP_MALE, 'pay_scale_id' => $this->formula('Group')->id]);

        $this->actingAs($this->manager('grades'))->get(route('grades.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('definition.fields.1.required', true)
                ->where('definition.fields.1.options', fn ($options) => array_column($options->toArray(), 'label') === [
                    'Group - Male', 'Group - Female or 65 Ages', 'VBL - Male', 'VBL - Female or 65 Ages',
                ])
                ->where('records.0.type', 'group_male'));
    }

    public function test_the_grades_page_sends_every_formula_and_how_many_grades_use_it(): void
    {
        Grade::create(['code' => '400', 'type' => Grade::GROUP_MALE, 'pay_scale_id' => $this->formula('VBL')->id]);
        $this->payScale('Contract', [['Gross', '15000']]);

        $this->actingAs($this->manager('grades'))->get(route('grades.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('payScales.formulas', 3)
                ->where('payScales.example_grade', 400)
                ->where('payScales.formulas.0', fn ($f) => $f['name'] === 'Group' && $f['grades_count'] === 0 && count($f['lines']) === 4)
                ->where('payScales.formulas.0.lines.0', fn ($l) => $l['title'] === 'Basic' && $l['formula'] === '[Grade] * 25 + 950' && $l['example'] == 10950 && str_starts_with($l['key'], 'l'))
                ->where('payScales.formulas.1', fn ($f) => $f['name'] === 'VBL' && $f['grades_count'] === 1)
                ->where('payScales.formulas.2', fn ($f) => $f['name'] === 'Contract' && $f['lines'][0]['example'] == 15000));
    }

    public function test_only_the_grades_page_carries_pay_scales(): void
    {
        $this->actingAs($this->manager('designations'))->get(route('designations.index'))
            ->assertInertia(fn (Assert $page) => $page->missing('payScales'));
        $this->actingAs($this->manager('grades'))->get(route('grades.index'))
            ->assertInertia(fn (Assert $page) => $page->missing('designationCategories'));
    }

    // ----- adding, editing and deleting formulas -----------------------------------

    /** What the page sends: a name and the components, as new ones (the Group formulas unless given). */
    private function formulasPayload(string $name, ?array $lines = null): array
    {
        $lines ??= [['Basic', '[Grade] * 25 + 950'], ['House Rent', '[Basic] / 2'], ['Conveyance', '2000'], ['Gross', '[Basic] + [House Rent] + [Conveyance]']];

        return [
            'name' => $name,
            'lines' => array_map(fn ($line, $i) => ['key' => 'n'.($i + 1), 'id' => null, 'title' => $line[0], 'formula' => $line[1]], $lines, array_keys($lines)),
        ];
    }

    public function test_a_formula_can_be_added_and_given_to_grades(): void
    {
        $user = $this->manager('grades');

        $this->actingAs($user)
            ->post(route('grades.pay-scales.store'), $this->formulasPayload('  Contract   Staff ', [['Gross', '[Grade] * 30 + 1000'], ['Basic', '[Gross] * 55%'], ['house  rent ', '[gross] * 35%']]))
            ->assertRedirect(route('grades.index'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Formula "Contract Staff" added. Grades can now be given it.');

        $contract = $this->formula('Contract Staff');                                             // spaces tidied
        $this->assertSame(['Gross', 'Basic', 'house rent'], $contract->lines->pluck('title')->all());
        $this->assertSame(['Gross' => 13000.0, 'Basic' => 7150.0, 'house rent' => 4550.0], $this->worked($contract, '400'));

        $this->actingAs($user)->post(route('grades.store'), $this->gradePayload(['pay_scale_id' => $contract->id]))->assertSessionHasNoErrors();
        $this->assertSame(13000.0, Grade::first()->calculations()[0]['value']);
    }

    public function test_a_formula_needs_a_name_of_its_own(): void
    {
        $user = $this->manager('grades');

        $this->actingAs($user)->post(route('grades.pay-scales.store'), $this->formulasPayload(''))->assertSessionHasErrors(['name' => 'Give the formula a name, e.g. Group.']);
        $this->actingAs($user)->post(route('grades.pay-scales.store'), $this->formulasPayload('group'))->assertSessionHasErrors(['name' => 'Another formula already has this name.']);
        $this->assertSame(2, PayScale::count());

        // Keeping its own name while editing is fine.
        $this->actingAs($user)->put(route('grades.pay-scales.update', $this->formula('Group')), $this->formulasPayload('GROUP'))->assertSessionHasNoErrors();
        $this->assertSame('GROUP', PayScale::find($this->formula('GROUP')->id)->name);
    }

    public function test_a_formula_can_be_renamed_and_its_components_edited_in_place_reordered_and_removed(): void
    {
        $user = $this->manager('grades');
        $group = $this->formula('Group');
        [$basic, $rent, $conveyance, $gross] = $group->lines->all();

        $this->actingAs($user)->put(route('grades.pay-scales.update', $group), ['name' => 'Group Staff', 'lines' => [
            ['key' => "l{$gross->id}", 'id' => $gross->id, 'title' => 'Gross', 'formula' => '[Basic] + [House Rent]'],
            ['key' => "l{$basic->id}", 'id' => $basic->id, 'title' => 'Basic', 'formula' => '[Grade] * 25 + 90'],
            ['key' => "l{$rent->id}", 'id' => $rent->id, 'title' => 'House Rent', 'formula' => '[Basic] * 40%'],
        ]])->assertSessionHasNoErrors()->assertSessionHas('success', 'Formula "Group Staff" updated.');

        $renamed = PayScale::with('lines')->find($group->id);
        $this->assertSame('Group Staff', $renamed->name);
        $this->assertSame(['Gross', 'Basic', 'House Rent'], $renamed->lines->pluck('title')->all());
        $this->assertSame([$gross->id, $basic->id, $rent->id], $renamed->lines->pluck('id')->all());    // the same rows, not new ones
        $this->assertNull(PayScaleLine::find($conveyance->id));
        $this->assertSame(['Gross' => 14126.0, 'Basic' => 10090.0, 'House Rent' => 4036.0], $this->worked($renamed, '400'));

        // VBL is untouched.
        $this->assertSame(4, $this->formula('VBL')->lines->count());
    }

    public function test_formulas_are_checked_before_they_are_saved(): void
    {
        $user = $this->manager('grades');
        $groupLines = [['Basic', '[Grade] * 25 + 950'], ['House Rent', '[Basic] / 2']];
        $with = fn (int $at, string $title, string $formula) => $this->formulasPayload('Group', array_replace($groupLines, [$at => [$title, $formula]]));

        foreach ([
            'lines.1.title' => [$with(1, '', '[Basic] / 2'), 'Give the component a title, e.g. Basic.'],
            'lines.1.title ' => [$with(1, 'basic', '[Basic] / 2'), 'Another component already has this title.'],
            'lines.1.title  ' => [$with(1, 'Grade', '[Basic] / 2'), '[Grade] is the grade number itself. Give the component another title.'],
            'lines.1.title   ' => [$with(1, 'House [Rent]', '5'), 'A title cannot have [ or ] in it.'],
            'lines.1.formula' => [$with(1, 'House Rent', ''), 'Enter a formula, e.g. [Grade] * 25 + 950 or 2000.'],
            'lines.1.formula ' => [$with(1, 'House Rent', '[Basic] /'), 'The formula ends too soon. Something is missing at the end.'],
            'lines.1.formula  ' => [$with(1, 'House Rent', 'basic / 2'), '"basic" is not understood. Put names in square brackets, e.g. [basic]. Functions you can use: min, max, round.'],
            'lines.1.formula   ' => [$with(1, 'House Rent', '[Gross] * 30%'), 'There is no component called [Gross]. You can use [Grade], [Basic], [House Rent].'],
            'lines.1.formula    ' => [$with(1, 'House Rent', '[House Rent] + 1'), '[House Rent] uses itself, so it can never be worked out.'],
            'lines.0.formula' => [$with(0, 'Basic', '[House Rent] * 2'), '[Basic] and [House Rent] use each other, so none of them can be worked out.'],
        ] as $field => [$payload, $message]) {
            $this->actingAs($user)->put(route('grades.pay-scales.update', $this->formula('Group')), $payload)
                ->assertSessionHasErrors([trim($field) => $message]);
        }

        // Nothing was saved: Group still has its four components.
        $this->assertSame(4, $this->formula('Group')->lines->count());
    }

    public function test_a_component_of_another_formula_cannot_be_edited_through_this_one(): void
    {
        $vblLine = $this->formula('VBL')->lines->first();

        $this->actingAs($this->manager('grades'))
            ->put(route('grades.pay-scales.update', $this->formula('Group')), ['name' => 'Group', 'lines' => [['key' => 'x1', 'id' => $vblLine->id, 'title' => 'Hijacked', 'formula' => '1']]])
            ->assertSessionHasErrors('lines.0.id');

        $this->assertSame('Gross', $vblLine->fresh()->title);
    }

    public function test_a_formula_grades_are_paid_by_cannot_be_deleted(): void
    {
        $user = $this->manager('grades');
        $grade = Grade::create(['code' => '400', 'type' => Grade::GROUP_MALE, 'pay_scale_id' => $this->formula('Group')->id]);

        $this->actingAs($user)->delete(route('grades.pay-scales.destroy', $this->formula('Group')))
            ->assertSessionHas('error', 'The formula "Group" cannot be deleted because 1 grade(s) are paid by it. Give them another formula first.');
        $this->assertSame(4, $this->formula('Group')->lines->count());

        $grade->update(['pay_scale_id' => $this->formula('VBL')->id]);
        $group = $this->formula('Group');
        $this->actingAs($user)->delete(route('grades.pay-scales.destroy', $group))->assertSessionHas('success', 'Formula "Group" deleted.');
        $this->assertNull(PayScale::find($group->id));
        $this->assertSame(0, PayScaleLine::where('pay_scale_id', $group->id)->count());   // its components went too
    }

    public function test_the_preview_works_out_unsaved_formulas_and_says_what_is_wrong(): void
    {
        $response = $this->actingAs($this->manager('grades'))
            ->postJson(route('grades.pay-scales.preview'), [
                'grade' => '400',
                'lines' => [
                    ['key' => 'a', 'title' => 'Gross', 'formula' => '[Grade] * 25 + 950'],
                    ['key' => 'b', 'title' => 'Basic', 'formula' => '[Gross] * 60%'],
                    ['key' => 'c', 'title' => 'Medical', 'formula' => '[Gros] * 10%'],   // typo
                    ['key' => 'd', 'title' => '', 'formula' => ''],                       // being typed
                ],
            ])
            ->assertOk();

        $response->assertJsonPath('values.a', 10950)
            ->assertJsonPath('values.b', 6570)
            ->assertJsonPath('values.c', null)
            ->assertJsonPath('errors', fn ($errors) => str_contains($errors['lines.2.formula'], 'no component called [Gros]')
                && isset($errors['lines.3.title'], $errors['lines.3.formula']));

        $this->assertSame(2, PayScale::count()); // nothing was saved
    }

    public function test_adding_editing_deleting_and_previewing_formulas_need_the_matching_grade_permission(): void
    {
        $viewer = $this->userWith(['view grades']);
        $creator = $this->userWith(['view grades', 'create grades']);
        $editor = $this->userWith(['view grades', 'edit grades']);
        $deleter = $this->userWith(['view grades', 'delete grades']);
        $group = $this->formula('Group');
        $spare = $this->payScale('Spare', [['Gross', '1']]);

        $this->actingAs($viewer)->postJson(route('grades.pay-scales.preview'), $this->formulasPayload('x'))->assertForbidden();
        $this->actingAs($viewer)->post(route('grades.pay-scales.store'), $this->formulasPayload('New'))->assertForbidden();
        $this->actingAs($editor)->post(route('grades.pay-scales.store'), $this->formulasPayload('New'))->assertForbidden();
        $this->actingAs($creator)->put(route('grades.pay-scales.update', $group), $this->formulasPayload('Group', [['Basic', '1']]))->assertForbidden();
        $this->actingAs($editor)->delete(route('grades.pay-scales.destroy', $spare))->assertForbidden();
        $this->assertSame(3, PayScale::count());
        $this->assertSame(4, $this->formula('Group')->lines->count());

        $this->actingAs($creator)->postJson(route('grades.pay-scales.preview'), $this->formulasPayload('x'))->assertOk();
        $this->actingAs($creator)->post(route('grades.pay-scales.store'), $this->formulasPayload('New'))->assertSessionHasNoErrors();
        $this->actingAs($editor)->put(route('grades.pay-scales.update', $group), $this->formulasPayload('Group', [['Basic', '1']]))->assertSessionHasNoErrors();
        $this->actingAs($deleter)->delete(route('grades.pay-scales.destroy', $spare))->assertSessionHas('success');

        $this->assertSame(['Group', 'VBL', 'New'], PayScale::orderBy('id')->pluck('name')->all());
        $this->assertSame(1, $this->formula('Group')->lines->count());
    }

    // ----- leave types -------------------------------------------------------

    public function test_the_carry_forward_cap_is_dropped_when_carry_forward_is_off(): void
    {
        $this->actingAs($this->manager('leave types'))->post(route('leave-types.store'), [
            'code' => '1001', 'name' => 'Casual', 'is_paid' => true, 'entitlement_days' => 10, 'accrual' => 'yearly',
            'carry_forward' => false, 'carry_forward_limit_days' => 5, 'attachment_required' => false,
        ])->assertSessionHasNoErrors();

        $this->assertNull(LeaveType::first()->carry_forward_limit_days);
    }

    public function test_the_carry_forward_cap_is_kept_when_carry_forward_is_on(): void
    {
        $this->actingAs($this->manager('leave types'))->post(route('leave-types.store'), [
            'code' => '1002', 'name' => 'Annual', 'is_paid' => true, 'entitlement_days' => 20, 'accrual' => 'monthly',
            'carry_forward' => true, 'carry_forward_limit_days' => 5, 'attachment_required' => false,
        ])->assertSessionHasNoErrors();

        $this->assertEquals(5, LeaveType::first()->carry_forward_limit_days);
    }

    public function test_leave_accrual_must_be_a_known_value(): void
    {
        $this->actingAs($this->manager('leave types'))->post(route('leave-types.store'), [
            'code' => '1001', 'name' => 'Casual', 'entitlement_days' => 10, 'accrual' => 'whenever',
        ])->assertSessionHasErrors('accrual');
    }

    // ----- offence types -----------------------------------------------------

    public function test_an_offence_can_be_re_graded_in_severity(): void
    {
        $user = $this->manager('offence types');
        $offence = OffenceType::create(['code' => '501', 'name' => 'Unauthorised absence', 'severity' => 'minor']);

        $this->actingAs($user)->put(route('offence-types.update', $offence), [
            'code' => '501', 'name' => 'Unauthorised absence', 'severity' => 'gross',
        ])->assertSessionHasNoErrors();

        $this->assertSame('gross', $offence->fresh()->severity);
    }

    public function test_an_offence_rejects_an_unknown_severity(): void
    {
        $this->actingAs($this->manager('offence types'))->post(route('offence-types.store'), [
            'code' => '501', 'name' => 'Unauthorised absence', 'severity' => 'catastrophic',
        ])->assertSessionHasErrors('severity');

        $this->assertSame(0, OffenceType::count());
    }

    public function test_the_offences_page_no_longer_offers_permitted_actions(): void
    {
        $this->actingAs($this->manager('offence types'))->get(route('offence-types.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('definition.fields', fn ($fields) => collect($fields)->pluck('key')->doesntContain('action_type_ids'))
                ->missing('lookups.action_type_ids'));
    }

    public function test_disciplinary_actions_are_not_part_of_master_data(): void
    {
        $this->assertNull(MasterDataRegistry::find('action-types'));
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('action-types.index'));
        $this->assertFalse(Permission::where('name', 'like', '%action types')->exists());
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('action_types'));
    }

    // ----- ACR templates -----------------------------------------------------

    private function acrPayload(array $overrides = []): array
    {
        return array_merge([
            'code' => '701',
            'name' => 'Manager ACR',
            'criteria' => [
                ['name' => 'Integrity', 'weight' => 60],
                ['name' => 'Punctuality', 'weight' => 40],
            ],
            'rating_scale' => [
                ['label' => 'Outstanding', 'min_score' => 80, 'max_score' => 100],
                ['label' => 'Good', 'min_score' => 50, 'max_score' => 79.99],
                ['label' => 'Poor', 'min_score' => 0, 'max_score' => 49.99],
            ],
            'is_active' => true,
        ], $overrides);
    }

    public function test_an_acr_template_links_its_designations(): void
    {
        $gm = Designation::create(['designation_category_id' => $this->designationCategory()->id, 'code' => '201', 'title' => 'General Manager', 'serial' => 1]);
        $agm = Designation::create(['designation_category_id' => $this->designationCategory()->id, 'code' => '202', 'title' => 'Asst. General Manager', 'serial' => 2]);

        $this->actingAs($this->manager('acr templates'))
            ->post(route('acr-templates.store'), $this->acrPayload(['designation_ids' => [$gm->id, $agm->id]]))
            ->assertSessionHasNoErrors();

        $template = AcrTemplate::first();
        $this->assertCount(2, $template->criteria);
        $this->assertCount(3, $template->rating_scale);
        $this->assertEqualsCanonicalizing([$gm->id, $agm->id], $template->designations->pluck('id')->all());
    }

    public function test_acr_criteria_weights_must_total_100(): void
    {
        $this->actingAs($this->manager('acr templates'))
            ->post(route('acr-templates.store'), $this->acrPayload(['criteria' => [
                ['name' => 'Integrity', 'weight' => 60],
                ['name' => 'Punctuality', 'weight' => 30],
            ]]))
            ->assertSessionHasErrors('criteria');

        $this->assertSame(0, AcrTemplate::count());
    }

    public function test_acr_rating_bands_must_not_overlap(): void
    {
        $this->actingAs($this->manager('acr templates'))
            ->post(route('acr-templates.store'), $this->acrPayload(['rating_scale' => [
                ['label' => 'Outstanding', 'min_score' => 80, 'max_score' => 100],
                ['label' => 'Good', 'min_score' => 70, 'max_score' => 85],
            ]]))
            ->assertSessionHasErrors('rating_scale');
    }

    public function test_an_acr_rating_band_must_run_low_to_high(): void
    {
        $this->actingAs($this->manager('acr templates'))
            ->post(route('acr-templates.store'), $this->acrPayload(['rating_scale' => [
                ['label' => 'Backwards', 'min_score' => 90, 'max_score' => 10],
            ]]))
            ->assertSessionHasErrors('rating_scale');
    }

    public function test_acr_needs_at_least_one_criterion_and_one_rating(): void
    {
        $this->actingAs($this->manager('acr templates'))
            ->post(route('acr-templates.store'), $this->acrPayload(['criteria' => [], 'rating_scale' => []]))
            ->assertSessionHasErrors(['criteria', 'rating_scale']);
    }

    public function test_malformed_acr_input_is_rejected_without_a_server_error(): void
    {
        $this->actingAs($this->manager('acr templates'))
            ->post(route('acr-templates.store'), $this->acrPayload(['criteria' => 'nope', 'rating_scale' => 'nope']))
            ->assertSessionHasErrors(['criteria', 'rating_scale']);
    }

    public function test_a_designation_used_by_an_acr_template_cannot_be_deleted(): void
    {
        $designation = Designation::create(['designation_category_id' => $this->designationCategory()->id, 'code' => '201', 'title' => 'General Manager', 'serial' => 1]);
        AcrTemplate::create($this->acrPayload())->designations()->attach($designation);

        $this->actingAs($this->manager('designations'))
            ->delete(route('designations.destroy', $designation))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('designations', ['id' => $designation->id]);
    }

    // ----- document types ----------------------------------------------------

    public function test_a_document_name_is_unique_within_a_module_only(): void
    {
        $user = $this->manager('document types');
        DocumentType::create(['code' => '601', 'name' => 'Photograph', 'module' => 'employee_profile', 'is_mandatory' => true]);

        $this->actingAs($user)->post(route('document-types.store'), ['code' => '602', 'name' => 'Photograph', 'module' => 'employee_profile'])
            ->assertSessionHasErrors('name');

        $this->actingAs($user)->post(route('document-types.store'), ['code' => '602', 'name' => 'Photograph', 'module' => 'recruitment'])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, DocumentType::count());
    }

    public function test_a_document_type_code_is_unique(): void
    {
        $user = $this->manager('document types');
        DocumentType::create(['code' => '603', 'name' => 'National ID', 'module' => 'employee_profile']);

        $this->actingAs($user)->post(route('document-types.store'), ['code' => '603', 'name' => 'NID copy', 'module' => 'recruitment'])
            ->assertSessionHasErrors('code');
    }

    public function test_a_document_must_belong_to_a_known_module(): void
    {
        $this->actingAs($this->manager('document types'))
            ->post(route('document-types.store'), ['code' => '601', 'name' => 'Photograph', 'module' => 'spaceship'])
            ->assertSessionHasErrors('module');
    }

    // ----- listing -----------------------------------------------------------

    public function test_the_index_orders_designations_by_serial_and_exposes_lookups(): void
    {
        Designation::create(['designation_category_id' => $this->designationCategory()->id, 'code' => '902', 'title' => 'Junior', 'serial' => 9]);
        Designation::create(['designation_category_id' => $this->designationCategory()->id, 'code' => '901', 'title' => 'Senior', 'serial' => 1]);

        $this->actingAs($this->manager('acr templates'))->get(route('acr-templates.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('MasterData/Index')
                ->where('lookups.designation_ids.0.label', '901 — Senior (General)')
                ->where('lookups.designation_ids.1.label', '902 — Junior (General)'));

        $this->actingAs($this->manager('designations'))->get(route('designations.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('records.0.code', '901')
                ->where('records.1.code', '902'));
    }
}
