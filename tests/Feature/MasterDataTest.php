<?php

namespace Tests\Feature;

use App\Models\AcrTemplate;
use App\Models\Designation;
use App\Models\DocumentType;
use App\Models\Grade;
use App\Models\LeaveType;
use App\Models\OffenceType;
use App\Models\SalaryType;
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

            'salary-types' => ['salary-types', 'salary types', [
                'code' => '120', 'name' => 'House Rent', 'calculation' => 'percent_of_basic', 'value' => 50, 'is_active' => true,
            ], SalaryType::class, ['code' => '120', 'name' => 'House Rent']],

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
        $designation = Designation::create(['code' => '101', 'title' => 'Managing Director', 'serial' => 1]);

        $this->actingAs($viewer)->get(route('designations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('MasterData/Index')
                ->where('definition.key', 'designations')
                ->where('can', ['create' => false, 'edit' => false, 'delete' => false, 'import' => false, 'export' => false])
                ->has('records', 1));

        $this->actingAs($viewer)->post(route('designations.store'), ['code' => '999', 'title' => 'X', 'serial' => 1])->assertForbidden();
        $this->actingAs($viewer)->put(route('designations.update', $designation), ['code' => '999', 'title' => 'X', 'serial' => 1])->assertForbidden();
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
        Designation::create(['code' => '201a', 'title' => 'General Manager', 'serial' => 3]);

        $this->actingAs($user)->post(route('designations.store'), ['code' => '201A', 'title' => 'Another', 'serial' => 4])
            ->assertSessionHasErrors('code');

        $this->assertSame(1, Designation::count());
    }

    public function test_capitals_and_spaces_around_a_code_are_tidied_before_it_is_saved(): void
    {
        $this->actingAs($this->manager('designations'))
            ->post(route('designations.store'), ['code' => '  GM101  ', 'title' => 'General Manager', 'serial' => 1])
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
            ->post(route('designations.store'), ['code' => $code, 'title' => 'X', 'serial' => 1])
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
            ->post(route('designations.store'), ['code' => $code, 'title' => 'X', 'serial' => 1])
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
        $designation = Designation::create(['code' => '201', 'title' => 'General Manager', 'serial' => 3]);

        $this->actingAs($user)->put(route('designations.update', $designation), ['code' => '201', 'title' => 'GM Operations', 'serial' => 3])
            ->assertSessionHasNoErrors();

        $this->assertSame('GM Operations', $designation->fresh()->title);
    }

    public function test_update_of_a_missing_record_is_404(): void
    {
        $this->actingAs($this->manager('designations'))
            ->put(route('designations.update', 999), ['code' => '999', 'title' => 'X', 'serial' => 1])
            ->assertNotFound();
    }

    // ----- salary types ------------------------------------------------------

    private function salaryType(string $code = '120', string $name = 'House Rent', float $value = 50, string $calculation = 'percent_of_basic', bool $active = true): SalaryType
    {
        return SalaryType::create(['code' => $code, 'name' => $name, 'calculation' => $calculation, 'value' => $value, 'is_active' => $active]);
    }

    private function salaryTypePayload(array $overrides = []): array
    {
        return array_merge(['code' => '652', 'name' => 'Conveyance', 'calculation' => 'percent_of_basic', 'value' => 10, 'is_active' => true], $overrides);
    }

    public function test_no_salary_types_are_provided_out_of_the_box(): void
    {
        $this->assertSame(0, SalaryType::count());
    }

    public function test_grade_types_no_longer_exist(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('grade-types.index'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('grade_types'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('grades', 'grade_type_id'));
        $this->assertFalse(Permission::where('name', 'like', '%grade types')->exists());
    }

    public function test_salary_type_codes_can_be_numbers(): void
    {
        $this->actingAs($this->manager('salary types'))
            ->post(route('salary-types.store'), $this->salaryTypePayload(['code' => '0652']))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('salary_types', ['code' => '0652', 'name' => 'Conveyance']);
    }

    public function test_a_percentage_cannot_exceed_100_but_a_fixed_amount_can(): void
    {
        $user = $this->manager('salary types');

        $this->actingAs($user)->post(route('salary-types.store'), $this->salaryTypePayload(['value' => 150]))
            ->assertSessionHasErrors('value');

        $this->actingAs($user)->post(route('salary-types.store'), $this->salaryTypePayload(['calculation' => 'fixed', 'value' => 2500]))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('salary_types', ['code' => '652', 'calculation' => 'fixed', 'value' => 2500]);
    }

    public function test_a_salary_types_value_must_be_a_non_negative_number(): void
    {
        $user = $this->manager('salary types');

        $this->actingAs($user)->post(route('salary-types.store'), $this->salaryTypePayload(['value' => -1]))->assertSessionHasErrors('value');
        $this->actingAs($user)->post(route('salary-types.store'), $this->salaryTypePayload(['value' => 'ten']))->assertSessionHasErrors('value');
        $this->actingAs($user)->post(route('salary-types.store'), $this->salaryTypePayload(['value' => null]))->assertSessionHasErrors('value');
        $this->actingAs($user)->post(route('salary-types.store'), $this->salaryTypePayload(['calculation' => 'bonus']))->assertSessionHasErrors('calculation');
    }

    public function test_salary_type_names_are_unique_regardless_of_case(): void
    {
        $this->salaryType('120', 'House Rent');

        $this->actingAs($this->manager('salary types'))
            ->post(route('salary-types.store'), $this->salaryTypePayload(['name' => 'house rent']))
            ->assertSessionHasErrors(['name' => 'A salary type with this name already exists.']);
    }

    public function test_a_salary_type_in_use_cannot_be_deleted(): void
    {
        $type = $this->salaryType();
        $grade = Grade::create(['code' => 'G1', 'name' => 'Grade 1', 'basic_min' => 1, 'basic_max' => 2]);
        $grade->salaryTypes()->attach($type);

        $this->actingAs($this->manager('salary types'))
            ->delete(route('salary-types.destroy', $type))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('salary_types', ['id' => $type->id]);
    }

    public function test_the_salary_types_page_shows_the_rate_and_type(): void
    {
        $this->salaryType('120', 'House Rent', 50);
        $this->salaryType('652', 'Medical Allowance', 750, 'fixed');

        $this->actingAs($this->manager('salary types'))->get(route('salary-types.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('records.0.code', '120')
                ->where('records.0.calculation', 'percent_of_basic')
                ->where('records.1.calculation', 'fixed')
                ->where('definition.columns.3.type', 'rate'));
    }

    // ----- grades ------------------------------------------------------------

    private function gradePayload(array $overrides = []): array
    {
        return array_merge([
            'code' => '301', 'name' => 'Grade 1',
            'basic_min' => 20000, 'basic_max' => 35000, 'salary_type_ids' => [], 'is_active' => true,
        ], $overrides);
    }

    public function test_a_manager_can_create_update_and_delete_a_grade(): void
    {
        $user = $this->manager('grades');
        $rent = $this->salaryType('120', 'House Rent', 50);
        $conveyance = $this->salaryType('652', 'Conveyance', 2500, 'fixed');
        $payload = $this->gradePayload(['salary_type_ids' => [$rent->id, $conveyance->id]]);

        $this->actingAs($user)->post(route('grades.store'), $payload)->assertSessionHasNoErrors();

        $grade = Grade::first();
        $this->assertEqualsCanonicalizing([$rent->id, $conveyance->id], $grade->salaryTypes()->pluck('salary_types.id')->all());

        $this->actingAs($user)->put(route('grades.update', $grade), array_merge($payload, ['name' => 'Renamed', 'salary_type_ids' => [$rent->id]]))
            ->assertSessionHasNoErrors();
        $this->assertSame('Renamed', $grade->fresh()->name);
        $this->assertSame([$rent->id], $grade->salaryTypes()->pluck('salary_types.id')->all());

        $this->actingAs($user)->delete(route('grades.destroy', $grade))->assertRedirect(route('grades.index'));
        $this->assertSame(0, Grade::count());
        $this->assertDatabaseHas('salary_types', ['id' => $rent->id]); // only the link goes
        $this->assertDatabaseCount('grade_salary_type', 0);
    }

    public function test_a_grades_maximum_basic_cannot_be_below_its_minimum(): void
    {
        $this->actingAs($this->manager('grades'))
            ->post(route('grades.store'), $this->gradePayload(['basic_min' => 30000, 'basic_max' => 20000]))
            ->assertSessionHasErrors('basic_max');
    }

    public function test_a_grade_may_have_no_salary_types(): void
    {
        $this->actingAs($this->manager('grades'))
            ->post(route('grades.store'), $this->gradePayload(['salary_type_ids' => []]))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, Grade::first()->salaryTypes()->count());
    }

    public function test_a_grade_needs_existing_salary_types(): void
    {
        $user = $this->manager('grades');
        $rent = $this->salaryType();

        $this->actingAs($user)->post(route('grades.store'), $this->gradePayload(['salary_type_ids' => [9999]]))
            ->assertSessionHasErrors('salary_type_ids.0');
        $this->actingAs($user)->post(route('grades.store'), $this->gradePayload(['salary_type_ids' => [$rent->id, $rent->id]]))
            ->assertSessionHasErrors('salary_type_ids.0');

        $this->assertSame(0, Grade::count());
    }

    public function test_grade_codes_are_unique_regardless_of_case(): void
    {
        Grade::create(['code' => '3a', 'name' => 'Grade 1', 'basic_min' => 1, 'basic_max' => 2]);

        $this->actingAs($this->manager('grades'))
            ->post(route('grades.store'), $this->gradePayload(['code' => '3A']))
            ->assertSessionHasErrors('code');

        $this->assertSame(1, Grade::count());
    }

    public function test_the_gross_range_is_worked_out_from_the_salary_types(): void
    {
        $rent = $this->salaryType('120', 'House Rent', 50);                 // 50% of basic
        $medical = $this->salaryType('652', 'Medical', 10);                 // 10% of basic
        $conveyance = $this->salaryType('700', 'Conveyance', 2500, 'fixed'); // 2,500 flat
        $grade = Grade::create(['code' => 'G1', 'name' => 'Grade 1', 'basic_min' => 20000, 'basic_max' => 35000]);
        $grade->salaryTypes()->attach([$rent->id, $medical->id, $conveyance->id]);

        $this->actingAs($this->manager('grades'))->get(route('grades.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('records.0.gross_min', 20000 + 10000 + 2000 + 2500)
                ->where('records.0.gross_max', 35000 + 17500 + 3500 + 2500)
                ->where('records.0.salary_type_ids', fn ($ids) => count($ids) === 3));
    }

    public function test_an_inactive_salary_type_is_not_counted_in_the_gross(): void
    {
        $rent = $this->salaryType('120', 'House Rent', 50);
        $old = $this->salaryType('652', 'Old Allowance', 20, 'percent_of_basic', active: false);
        $grade = Grade::create(['code' => 'G1', 'name' => 'Grade 1', 'basic_min' => 1000, 'basic_max' => 2000]);
        $grade->salaryTypes()->attach([$rent->id, $old->id]);

        $this->assertSame(1500.0, $grade->load('salaryTypes')->grossAt(1000));
        $this->assertSame(3000.0, $grade->grossAt(2000));
    }

    public function test_amounts_are_rounded_to_two_decimals(): void
    {
        $type = $this->salaryType('120', 'Rent', 33.33);

        $this->assertSame(33.33, $type->amountFor(100));
        $this->assertSame(333.3, $type->amountFor(1000));
        $this->assertSame(1000.0, $this->salaryType('200', 'Flat', 1000, 'fixed')->amountFor(99999));
        $this->assertSame(0.0, $type->amountFor(null));
    }

    public function test_the_grades_page_offers_the_salary_types_with_their_rates(): void
    {
        $this->salaryType('120', 'House Rent', 50);
        $this->salaryType('652', 'Old', 5, 'fixed', active: false);

        $this->actingAs($this->manager('grades'))->get(route('grades.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('lookups.salary_type_ids.0.label', '120 — House Rent')
                ->where('lookups.salary_type_ids.0.calculation', 'percent_of_basic')
                ->where('lookups.salary_type_ids.0.rate', 50)
                ->where('lookups.salary_type_ids.0.active', true)
                ->where('lookups.salary_type_ids.1.label', '652 — Old (inactive)')
                ->where('lookups.salary_type_ids.1.active', false)
                ->where('definition.fields.4.preselect_active', true));
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
        $gm = Designation::create(['code' => '201', 'title' => 'General Manager', 'serial' => 1]);
        $agm = Designation::create(['code' => '202', 'title' => 'Asst. General Manager', 'serial' => 2]);

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
        $designation = Designation::create(['code' => '201', 'title' => 'General Manager', 'serial' => 1]);
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
        Designation::create(['code' => '902', 'title' => 'Junior', 'serial' => 9]);
        Designation::create(['code' => '901', 'title' => 'Senior', 'serial' => 1]);

        $this->actingAs($this->manager('acr templates'))->get(route('acr-templates.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('MasterData/Index')
                ->where('lookups.designation_ids.0.label', '901 — Senior')
                ->where('lookups.designation_ids.1.label', '902 — Junior'));

        $this->actingAs($this->manager('designations'))->get(route('designations.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('records.0.code', '901')
                ->where('records.1.code', '902'));
    }
}
