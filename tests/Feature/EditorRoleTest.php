<?php

namespace Tests\Feature;

use App\Models\Designation;
use App\Models\SalaryType;
use App\Models\Unit;
use App\Models\User;
use App\Support\MasterData\MasterDataRegistry;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 1 is entering master data. The Editor does that, so it needs full access
 * to every master and to Units, and nothing beyond. The system keeps only four roles.
 */
class EditorRoleTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_11_19_000003_restrict_roles_and_add_editor_role.php';

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->syncRoles([Role::findOrCreate($role)]);

        return $user;
    }

    private function userWith(array $permissions): User
    {
        $role = Role::create(['name' => 'Role '.uniqid()]);
        $role->givePermissionTo(array_map(fn ($p) => Permission::findOrCreate($p), $permissions));

        $user = User::factory()->create();
        $user->syncRoles([$role]);

        return $user;
    }

    private function editor(): User
    {
        return $this->userWithRole('Editor');
    }

    /** A valid create payload for each master, and the field to change when editing. */
    private function payloads(): array
    {
        return [
            'departments' => ['code' => '900', 'name' => 'Logistics', 'short_name' => 'LOG', 'is_active' => true],
            'designations' => ['code' => '201', 'title' => 'General Manager', 'serial' => 3, 'is_active' => true],
            'salary-types' => ['code' => '120', 'name' => 'House Rent', 'calculation' => 'percent_of_basic', 'value' => 50, 'is_active' => true],
            'grades' => ['code' => '301', 'name' => 'Grade 1', 'basic_min' => 10000, 'basic_max' => 20000,
                'salary_type_ids' => [SalaryType::create(['code' => '652', 'name' => 'Medical', 'calculation' => 'percent_of_basic', 'value' => 10])->id], 'is_active' => true],
            'leave-types' => ['code' => '1001', 'name' => 'Casual Leave', 'is_paid' => true, 'entitlement_days' => 10,
                'accrual' => 'yearly', 'carry_forward' => false, 'attachment_required' => false, 'is_active' => true],
            'offence-types' => ['code' => '501', 'name' => 'Unauthorised absence', 'severity' => 'minor', 'is_active' => true],
            'acr-templates' => ['code' => '701', 'name' => 'Officer ACR',
                'criteria' => [['name' => 'Integrity', 'weight' => 60], ['name' => 'Punctuality', 'weight' => 40]],
                'rating_scale' => [['label' => 'Poor', 'min_score' => 0, 'max_score' => 49.99], ['label' => 'Good', 'min_score' => 50, 'max_score' => 100]],
                'designation_ids' => [Designation::create(['code' => 'MD', 'title' => 'Managing Director', 'serial' => 1])->id],
                'is_active' => true],
            'document-types' => ['code' => '603', 'name' => 'National ID', 'module' => 'employee_profile', 'is_mandatory' => true, 'is_active' => true],
        ];
    }

    // ----- the role set ----------------------------------------------------------

    public function test_seeding_leaves_exactly_the_four_roles(): void
    {
        // Roles from before the cut-down, to prove they are cleaned up.
        foreach (['HR Manager', 'HR Executive', 'Auditor', 'Employee'] as $old) {
            Role::findOrCreate($old);
        }

        $this->seed(RolePermissionSeeder::class);

        $this->assertEqualsCanonicalizing(
            ['Super System Admin', 'Applicant', 'Editor', 'Top Management'],
            Role::pluck('name')->all(),
        );
    }

    public function test_the_migration_removes_other_roles_and_gives_stranded_users_applicant(): void
    {
        $manager = Role::create(['name' => 'HR Manager']);
        $auditor = Role::create(['name' => 'Auditor']);
        $top = Role::findOrCreate('Top Management');

        $onlyManager = User::factory()->create();
        $onlyManager->syncRoles([$manager]);
        $managerAndTop = User::factory()->create();
        $managerAndTop->syncRoles([$manager, $top]);
        $auditorOnly = User::factory()->create();
        $auditorOnly->syncRoles([$auditor]);

        (require base_path(self::MIGRATION))->up();

        $this->assertEqualsCanonicalizing(['Applicant', 'Editor', 'Top Management'], Role::pluck('name')->all());
        $this->assertSame(['Applicant'], $onlyManager->fresh()->getRoleNames()->all());
        $this->assertSame(['Applicant'], $auditorOnly->fresh()->getRoleNames()->all());
        // Someone who already has a kept role isn't given a second one.
        $this->assertSame(['Top Management'], $managerAndTop->fresh()->getRoleNames()->all());
    }

    public function test_the_migration_is_safe_to_run_twice_and_keeps_edits_to_the_editor_role(): void
    {
        $editor = Role::findByName('Editor');
        $editor->givePermissionTo(Permission::findOrCreate('view calendar'));

        (require base_path(self::MIGRATION))->up();

        $this->assertTrue($editor->fresh()->hasPermissionTo('view calendar'));
        $this->assertSame(1, Role::where('name', 'Editor')->count());
    }

    // ----- the Editor can run every master -------------------------------------------

    public function test_the_editor_has_view_and_manage_for_every_master(): void
    {
        $editor = Role::findByName('Editor');

        foreach (MasterDataRegistry::permissionNames() as $permission) {
            $this->assertTrue($editor->hasPermissionTo($permission), "Editor lacks {$permission}");
        }
        foreach (['manage units', 'view units', 'create units', 'edit units', 'delete units', 'view dashboard'] as $permission) {
            $this->assertTrue($editor->hasPermissionTo($permission), "Editor lacks {$permission}");
        }
    }

    public function test_the_editor_can_open_the_dashboard_and_every_master(): void
    {
        $editor = $this->editor();

        $this->actingAs($editor)->get(route('dashboard'))->assertOk();
        foreach (MasterDataRegistry::keys() as $key) {
            $this->actingAs($editor)->get(route("{$key}.index"))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('canManage', true));
        }
        $this->actingAs($editor)->get(route('units.index'))->assertOk();
    }

    public function test_the_editor_can_create_edit_and_delete_in_every_master(): void
    {
        $editor = $this->editor();

        foreach ($this->payloads() as $key => $payload) {
            $definition = MasterDataRegistry::find($key);
            $model = $definition['model'];
            $title = $definition['title_key'];

            $this->actingAs($editor)->post(route("{$key}.store"), $payload)
                ->assertSessionHasNoErrors()
                ->assertRedirect(route("{$key}.index"));

            $record = $model::query()->where($title, $payload[$title])->first();
            $this->assertNotNull($record, "{$key}: record was not created");

            $changed = $payload[$title].' (edited)';
            $this->actingAs($editor)->put(route("{$key}.update", $record), [$title => $changed] + $payload)
                ->assertSessionHasNoErrors();
            $this->assertSame($changed, $record->fresh()->{$title}, "{$key}: edit was not saved");

            $this->actingAs($editor)->delete(route("{$key}.destroy", $record))
                ->assertSessionHas('success');
            $this->assertNull($model::query()->find($record->id), "{$key}: record was not deleted");
        }
    }

    public function test_every_master_has_a_payload_in_this_test(): void
    {
        // A new master must be added to payloads() so the Editor test covers it.
        $this->assertEqualsCanonicalizing(MasterDataRegistry::keys(), array_keys($this->payloads()));
    }

    public function test_the_editor_can_run_units_end_to_end(): void
    {
        $editor = $this->editor();

        $this->actingAs($editor)->get(route('units.create'))->assertOk();
        $this->actingAs($editor)->post(route('units.store'), ['unit_code' => '9001', 'name' => 'Factory One', 'is_active' => true])
            ->assertRedirect(route('units.index'));

        $unit = Unit::where('unit_code', '9001')->firstOrFail();
        $this->actingAs($editor)->get(route('units.show', $unit))->assertOk();
        $this->actingAs($editor)->get(route('units.edit', $unit))->assertOk();
        $this->actingAs($editor)->put(route('units.update', $unit), ['unit_code' => '9001', 'name' => 'Factory 1', 'is_active' => true])
            ->assertRedirect(route('units.index'));
        $this->assertSame('Factory 1', $unit->fresh()->name);

        $this->actingAs($editor)->delete(route('units.destroy', $unit))->assertSessionHas('success');
        $this->assertSoftDeleted($unit);
        $this->actingAs($editor)->post(route('units.restore', $unit->id))->assertSessionHas('success');
        $this->assertNotSoftDeleted($unit);
        $this->actingAs($editor)->delete(route('units.destroy', $unit));
        $this->actingAs($editor)->delete(route('units.force-destroy', $unit->id))->assertSessionHas('success');
        $this->assertDatabaseMissing('units', ['id' => $unit->id]);

        $this->actingAs($editor)->get(route('units.template'))->assertOk();
        $csv = "unit_code,short_form,name,location,contact_person,phone_number,email,is_active\n9100,HO,Head Office,Dhaka,,,,1\n";
        $this->actingAs($editor)->post(route('units.import'), ['file' => UploadedFile::fake()->createWithContent('units.csv', $csv)])
            ->assertSessionHas('success');
        $this->assertDatabaseHas('units', ['unit_code' => '9100', 'name' => 'Head Office']);
    }

    public function test_the_editor_gets_nothing_beyond_master_data(): void
    {
        $editor = $this->editor();

        $this->actingAs($editor)->get(route('roles.index'))->assertForbidden();
        $this->actingAs($editor)->get(route('users.index'))->assertForbidden();
        $this->actingAs($editor)->get(route('settings.index'))->assertForbidden();
        $this->actingAs($editor)->get(route('audits.index'))->assertForbidden();
    }

    // ----- Units: each change needs its own permission --------------------------------

    public function test_a_view_only_user_cannot_change_units(): void
    {
        $unit = Unit::create(['unit_code' => '1', 'name' => 'Unit', 'is_active' => true]);
        $viewer = $this->userWith(['view units']);

        $this->actingAs($viewer)->get(route('units.index'))->assertOk();
        $this->actingAs($viewer)->get(route('units.show', $unit))->assertOk();

        $this->actingAs($viewer)->get(route('units.create'))->assertForbidden();
        $this->actingAs($viewer)->post(route('units.store'), ['unit_code' => '2', 'name' => 'X'])->assertForbidden();
        $this->actingAs($viewer)->get(route('units.edit', $unit))->assertForbidden();
        $this->actingAs($viewer)->put(route('units.update', $unit), ['unit_code' => '1', 'name' => 'Hacked'])->assertForbidden();
        $this->actingAs($viewer)->delete(route('units.destroy', $unit))->assertForbidden();
        $this->actingAs($viewer)->post(route('units.restore', $unit->id))->assertForbidden();
        $this->actingAs($viewer)->delete(route('units.force-destroy', $unit->id))->assertForbidden();
        $this->actingAs($viewer)->post(route('units.import'), ['file' => UploadedFile::fake()->createWithContent('u.csv', "unit_code,name\n3,Y\n")])->assertForbidden();

        $this->assertSame('Unit', $unit->fresh()->name);
        $this->assertDatabaseCount('units', 1);
    }

    public function test_each_unit_permission_unlocks_only_its_own_action(): void
    {
        $unit = Unit::create(['unit_code' => '1', 'name' => 'Unit', 'is_active' => true]);

        $creator = $this->userWith(['create units']);
        $this->actingAs($creator)->get(route('units.create'))->assertOk();
        $this->actingAs($creator)->post(route('units.store'), ['unit_code' => '2', 'name' => 'New'])->assertRedirect();
        $this->actingAs($creator)->put(route('units.update', $unit), ['unit_code' => '1', 'name' => 'X'])->assertForbidden();
        $this->actingAs($creator)->delete(route('units.destroy', $unit))->assertForbidden();

        $editorOnly = $this->userWith(['edit units']);
        $this->actingAs($editorOnly)->put(route('units.update', $unit), ['unit_code' => '1', 'name' => 'Renamed'])->assertRedirect();
        $this->actingAs($editorOnly)->post(route('units.store'), ['unit_code' => 'U3', 'name' => 'X'])->assertForbidden();

        $deleter = $this->userWith(['delete units']);
        $this->actingAs($deleter)->put(route('units.update', $unit), ['unit_code' => '1', 'name' => 'X'])->assertForbidden();
        $this->actingAs($deleter)->delete(route('units.destroy', $unit))->assertSessionHas('success');

        $stranger = $this->userWith(['view dashboard']);
        $this->actingAs($stranger)->get(route('units.index'))->assertForbidden();
    }

    // ----- the permission matrix ----------------------------------------------------------

    public function test_the_role_matrix_lists_every_master_with_its_permissions(): void
    {
        $admin = $this->userWithRole('Super System Admin');

        $this->actingAs($admin)->get(route('roles.index'))
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $page->component('SuperAdmin/Roles/Index')
                    ->has('masterData', count(MasterDataRegistry::keys()))
                    ->where('masterData.0.label', 'Departments')
                    ->where('masterData.0.view', 'view departments')
                    ->where('masterData.0.manage', 'manage departments');
            });

        // Every checkbox in the matrix maps to a permission that exists, or ticking it would fail.
        foreach (MasterDataRegistry::permissionNames() as $permission) {
            $this->assertTrue(Permission::where('name', $permission)->exists(), "missing {$permission}");
        }
    }

    public function test_ticking_a_master_permission_in_the_matrix_takes_effect(): void
    {
        $admin = $this->userWithRole('Super System Admin');
        $role = Role::findOrCreate('Top Management');
        $topUser = $this->userWithRole('Top Management');

        $this->actingAs($topUser)->get(route('designations.index'))->assertForbidden();

        $this->actingAs($admin)->patch(route('roles.permissions.update', $role), [
            'permissions' => ['view dashboard', 'view designations'],
        ])->assertSessionHasNoErrors();

        $this->actingAs($topUser)->get(route('designations.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canManage', false));
    }
}
