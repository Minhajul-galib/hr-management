<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Designation;
use App\Models\DesignationCategory;
use App\Models\Grade;
use App\Models\PayScale;
use App\Models\User;
use App\Support\MasterData\MasterDataRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Every master has a Trash, as Units do: deleting (one row, or several ticked together) moves a
 * record there, from where it is restored or deleted for good. A trashed record keeps its code.
 */
class MasterDataTrashTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(string ...$permissions): User
    {
        $role = Role::create(['name' => 'Role '.uniqid()]);
        $role->givePermissionTo(array_map(fn ($p) => Permission::findOrCreate($p), $permissions));
        $user = User::factory()->create();
        $user->syncRoles([$role]);

        return $user;
    }

    private function manager(string $key): User
    {
        return $this->userWith('manage '.MasterDataRegistry::find($key)['permission']);
    }

    /** @return array<string, array{0: string}> */
    public static function masters(): array
    {
        return array_combine(MasterDataRegistry::keys(), array_map(fn ($key) => [$key], MasterDataRegistry::keys()));
    }

    /** A valid create payload for master $key; $n makes codes and names differ between records. */
    private function payload(string $key, int $n): array
    {
        return match ($key) {
            'departments' => ['code' => "90{$n}", 'name' => "Department {$n}", 'short_name' => "D{$n}", 'is_active' => true],
            'designations' => ['designation_category_id' => $this->designationCategory()->id, 'code' => "20{$n}", 'title' => "Title {$n}", 'serial' => $n, 'is_active' => true],
            'grades' => ['code' => "30{$n}", 'type' => Grade::GROUP_MALE, 'pay_scale_id' => PayScale::firstWhere('name', 'Group')->id, 'is_active' => true],
            'leave-types' => ['code' => "100{$n}", 'name' => "Leave {$n}", 'is_paid' => true, 'entitlement_days' => 10, 'accrual' => 'yearly', 'carry_forward' => false, 'attachment_required' => false, 'is_active' => true],
            'offence-types' => ['code' => "50{$n}", 'name' => "Offence {$n}", 'severity' => 'minor', 'is_active' => true],
            'acr-templates' => ['code' => "70{$n}", 'name' => "ACR {$n}",
                'criteria' => [['name' => 'Integrity', 'weight' => 60], ['name' => 'Punctuality', 'weight' => 40]],
                'rating_scale' => [['label' => 'Poor', 'min_score' => 0, 'max_score' => 49.99], ['label' => 'Good', 'min_score' => 50, 'max_score' => 100]],
                'designation_ids' => [Designation::firstOrCreate(['code' => 'md'], ['designation_category_id' => $this->designationCategory()->id, 'title' => 'Managing Director', 'serial' => 1])->id],
                'is_active' => true],
            'document-types' => ['code' => "60{$n}", 'name' => "Document {$n}", 'module' => 'employee_profile', 'is_mandatory' => true, 'is_active' => true],
        };
    }

    /** Creates a record the way the form does, and returns it. */
    private function make(string $key, int $n, User $user): \Illuminate\Database\Eloquent\Model
    {
        $payload = $this->payload($key, $n);
        $this->actingAs($user)->post(route("{$key}.store"), $payload)->assertSessionHasNoErrors();

        return MasterDataRegistry::find($key)['model']::query()->where('code', $payload['code'])->firstOrFail();
    }

    private function bulk(User $user, string $key, string $action, array $ids, array $query = [])
    {
        return $this->actingAs($user)->post(route("{$key}.bulk", $query), ['action' => $action, 'ids' => $ids]);
    }

    // ----- every master ------------------------------------------------------------------

    public function test_every_master_has_a_trash(): void
    {
        $user = $this->userWith(...MasterDataRegistry::permissionNames());

        foreach (MasterDataRegistry::keys() as $key) {
            $this->actingAs($user)->get(route("{$key}.index"))->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('definition.trash', true)->where('trash', false)->where('trashedCount', 0));
        }
    }

    #[DataProvider('masters')]
    public function test_deleting_moves_a_record_to_the_trash_where_it_still_holds_its_code(string $key): void
    {
        $user = $this->manager($key);
        $record = $this->make($key, 1, $user);

        $this->actingAs($user)->delete(route("{$key}.destroy", $record))->assertSessionHas('success');

        $this->assertSoftDeleted($record);
        $this->actingAs($user)->get(route("{$key}.index"))->assertInertia(fn (Assert $page) => $page
            ->has('records', 0)->where('trashedCount', 1));
        $this->actingAs($user)->get(route("{$key}.index", ['trash' => 1]))->assertInertia(fn (Assert $page) => $page
            ->where('trash', true)->has('records', 1)->where('records.0.id', $record->id));

        // The code is still taken, so it cannot be given to another record.
        $this->actingAs($user)->post(route("{$key}.store"), $this->payload($key, 1))->assertSessionHasErrors('code');
        $this->assertSame(1, $record::withTrashed()->count() - ($key === 'designations' ? $record::withTrashed()->where('code', 'md')->count() : 0));
    }

    #[DataProvider('masters')]
    public function test_ticked_rows_are_trashed_restored_and_deleted_for_good_together(string $key): void
    {
        $user = $this->manager($key);
        $model = MasterDataRegistry::find($key)['model'];
        [$one, $two, $three] = [$this->make($key, 1, $user), $this->make($key, 2, $user), $this->make($key, 3, $user)];

        // Two to the Trash.
        $this->bulk($user, $key, 'trash', [$one->id, $two->id])->assertSessionHas('success', "2 {$this->plural($key)} moved to the Trash.");
        $this->assertSoftDeleted($one);
        $this->assertSoftDeleted($two);
        $this->assertNotSoftDeleted($three);

        // One back out.
        $this->bulk($user, $key, 'restore', [$one->id])->assertSessionHas('success', "1 {$this->singular($key)} restored.");
        $this->assertNotSoftDeleted($one);
        $this->assertSoftDeleted($two);

        // One deleted for good from the Trash, one from the list.
        $this->bulk($user, $key, 'force', [$two->id, $three->id])->assertSessionHas('success', "2 {$this->plural($key)} deleted permanently.");
        $this->assertNull($model::withTrashed()->find($two->id));
        $this->assertNull($model::withTrashed()->find($three->id));
        $this->assertNotNull($model::find($one->id));

        // Restoring something that is not in the Trash, or does not exist, changes nothing.
        $this->bulk($user, $key, 'restore', [$one->id, 999999])->assertSessionHas('error');
    }

    private function singular(string $key): string
    {
        return mb_strtolower(MasterDataRegistry::find($key)['singular']);
    }

    private function plural(string $key): string
    {
        return mb_strtolower(MasterDataRegistry::find($key)['label']);
    }

    // ----- permissions and input ---------------------------------------------------------

    public function test_the_trash_and_bulk_actions_need_the_delete_permission(): void
    {
        $manager = $this->manager('departments');
        $department = $this->make('departments', 1, $manager);

        $viewer = $this->userWith('view departments');
        $this->actingAs($viewer)->get(route('departments.index', ['trash' => 1]))->assertForbidden();
        $this->bulk($viewer, 'departments', 'trash', [$department->id])->assertForbidden();
        $this->actingAs($viewer)->get(route('departments.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can.delete', false)->where('trashedCount', 0));
        $this->assertNotSoftDeleted($department);

        $deleter = $this->userWith('delete departments');
        $this->bulk($deleter, 'departments', 'trash', [$department->id])->assertSessionHas('success');
        $this->assertSoftDeleted($department);
        $this->actingAs($deleter)->get(route('departments.index', ['trash' => 1]))->assertOk();
    }

    public function test_a_bulk_request_must_name_an_action_and_some_rows(): void
    {
        $user = $this->manager('departments');

        $this->bulk($user, 'departments', 'explode', [1])->assertSessionHasErrors('action');
        $this->bulk($user, 'departments', 'trash', [])->assertSessionHasErrors('ids');
        $this->actingAs($user)->post(route('departments.bulk'), ['action' => 'trash'])->assertSessionHasErrors('ids');
        $this->actingAs($user)->post(route('departments.bulk'), ['action' => 'trash', 'ids' => range(1, 1001)])->assertSessionHasErrors('ids');
    }

    public function test_the_list_position_survives_a_bulk_action_in_the_trash(): void
    {
        $user = $this->manager('departments');
        $department = $this->make('departments', 1, $user);
        $department->delete();

        $this->bulk($user, 'departments', 'restore', [$department->id], ['trash' => 1, 'per_page' => 50])
            ->assertRedirect(route('departments.index', ['trash' => 1, 'per_page' => 50]));
    }

    public function test_the_trash_of_a_paged_master_is_searched_and_counted_apart(): void
    {
        $user = $this->manager('departments');
        $alive = $this->make('departments', 1, $user);
        $gone = $this->make('departments', 2, $user);
        $gone->delete();

        $this->actingAs($user)->get(route('departments.index', ['trash' => 1]))->assertInertia(fn (Assert $page) => $page
            ->where('trash', true)
            ->where('pagination.total', 1)
            ->where('records.0.id', $gone->id)
            ->where('stats.total', 1)); // the totals are of the live list

        $this->actingAs($user)->get(route('departments.index', ['trash' => 1, 'search' => 'Department 1']))
            ->assertInertia(fn (Assert $page) => $page->has('records', 0));
        $this->assertNotSoftDeleted($alive);
    }

    // ----- records in use ------------------------------------------------------------------

    public function test_a_record_in_use_stays_and_is_named_while_the_others_are_trashed(): void
    {
        $user = $this->manager('departments');
        $free = $this->make('departments', 1, $user);
        $busy = $this->make('departments', 2, $user);
        User::factory()->create(['department_id' => $busy->id]);

        $this->bulk($user, 'departments', 'trash', [$free->id, $busy->id])
            ->assertSessionHas('success', '1 department moved to the Trash.')
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Department 2') && str_contains($m, 'user(s) are assigned to it'));

        $this->assertSoftDeleted($free);
        $this->assertNotSoftDeleted($busy);

        // Deleting for good is guarded the same way.
        $this->bulk($user, 'departments', 'force', [$busy->id])->assertSessionHas('error');
        $this->assertNotNull(Department::find($busy->id));
    }

    public function test_a_designation_used_by_an_acr_template_cannot_be_trashed(): void
    {
        $user = $this->userWith('manage designations', 'manage acr templates');
        $this->make('acr-templates', 1, $user); // links the Managing Director designation
        $director = Designation::where('code', 'md')->firstOrFail();

        $this->bulk($user, 'designations', 'trash', [$director->id])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'used by 1 ACR template'));
        $this->assertNotSoftDeleted($director);
    }

    // ----- a trashed record still holds its name and code ---------------------------------

    public function test_a_trashed_department_still_holds_its_name(): void
    {
        $user = $this->manager('departments');
        $gone = $this->make('departments', 1, $user);
        $gone->delete();

        $this->actingAs($user)->post(route('departments.store'), ['code' => '777', 'name' => 'department 1', 'short_name' => 'X', 'is_active' => true])
            ->assertSessionHasErrors(['name' => 'A department with this name is in the Trash. Restore it, or delete it permanently, first.']);

        // Once it is deleted for good the name is free again.
        $this->bulk($user, 'departments', 'force', [$gone->id])->assertSessionHas('success');
        $this->actingAs($user)->post(route('departments.store'), ['code' => '777', 'name' => 'department 1', 'short_name' => 'X', 'is_active' => true])
            ->assertSessionHasNoErrors();
    }

    public function test_an_import_row_for_a_code_in_the_trash_is_refused_with_a_reason(): void
    {
        $user = $this->manager('departments');
        $this->make('departments', 1, $user)->delete(); // code 901

        $csv = "Code,Department Name,Short Name,Description,Active\n901,Another name,AN,,Yes\n902,Fresh,FR,,Yes\n";
        $json = $this->actingAs($user)->postJson(route('departments.import.preview'), ['file' => UploadedFile::fake()->createWithContent('d.csv', $csv)])
            ->assertOk()->json();

        $rows = collect($json['rows'])->keyBy('row');
        $this->assertSame('error', $rows[2]['status']);
        $this->assertStringContainsString('in the Trash', $rows[2]['errors']['code']);
        $this->assertSame('new', $rows[3]['status']);
        $this->assertSame(0, Department::count());
    }

    // ----- the formulas and categories on the same pages ---------------------------------------

    public function test_a_grade_in_the_trash_still_holds_its_formula_and_a_designation_its_category(): void
    {
        $user = $this->userWith('manage grades', 'manage designations');
        $formula = PayScale::firstWhere('name', 'Group');
        $grade = $this->make('grades', 1, $user);
        $grade->delete();

        $this->actingAs($user)->delete(route('grades.pay-scales.destroy', $formula))
            ->assertSessionHas('error', fn ($m) => str_contains($m, '1 of them in the Trash'));
        $this->assertNotNull(PayScale::find($formula->id));

        $category = DesignationCategory::create(['name' => 'Operators']);
        $designation = Designation::create(['designation_category_id' => $category->id, 'code' => '811', 'title' => 'Operator', 'serial' => 1]);
        $designation->delete();

        $this->actingAs($user)->delete(route('designations.categories.destroy', $category))
            ->assertSessionHas('error', fn ($m) => str_contains($m, '1 of them in the Trash'));
        $this->assertNotNull(DesignationCategory::find($category->id));
    }
}
