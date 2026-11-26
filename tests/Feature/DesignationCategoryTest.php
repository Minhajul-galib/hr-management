<?php

namespace Tests\Feature;

use App\Models\Designation;
use App\Models\DesignationCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Designations are not one ladder: each category (Merchandising, Machine Operators, General, ...)
 * is its own, with its own serials, and the user adds, renames and deletes the categories.
 */
class DesignationCategoryTest extends TestCase
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
        return $this->userWith(['view designations', 'manage designations']);
    }

    private function designation(string $code, string $title, DesignationCategory $category, int $serial = 0, bool $active = true): Designation
    {
        return Designation::create(['designation_category_id' => $category->id, 'code' => $code, 'title' => $title, 'serial' => $serial, 'is_active' => $active]);
    }

    private function csv(string $body): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('designations.csv', $body);
    }

    // ----- the page --------------------------------------------------------------

    public function test_the_page_lists_the_categories_with_how_many_designations_each_holds(): void
    {
        $general = $this->designationCategory();
        $merch = $this->designationCategory('Merchandising');
        $this->designationCategory('Machine Operators');
        $this->designation('201', 'Executive', $general, 1);
        $this->designation('301', 'Merchandiser', $merch, 1);
        $this->designation('302', 'Senior Merchandiser', $merch, 2);

        $this->actingAs($this->manager())->get(route('designations.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('MasterData/Index')
                ->has('designationCategories', 3)
                ->where('designationCategories.0', fn ($c) => $c['name'] === 'General' && $c['designations_count'] === 1)
                ->where('designationCategories.1', fn ($c) => $c['name'] === 'Merchandising' && $c['designations_count'] === 2)
                ->where('designationCategories.2', fn ($c) => $c['name'] === 'Machine Operators' && $c['designations_count'] === 0)
                // The form and the table's filter pick from the same list, each with its badge colour.
                ->has('lookups.designation_category_id', 3)
                ->where('lookups.designation_category_id.1.label', 'Merchandising')
                ->where('lookups.designation_category_id.1.color', fn ($c) => in_array($c, \App\Models\DesignationCategory::COLORS, true)));
    }

    public function test_each_category_is_its_own_ladder_ordered_by_its_own_serial(): void
    {
        $general = $this->designationCategory();
        $merch = $this->designationCategory('Merchandising');
        $this->designation('203', 'Manager', $general, 5);
        $this->designation('301', 'Merchandiser', $merch, 1);
        $this->designation('201', 'Executive', $general, 1);
        $this->designation('302', 'Senior Merchandiser', $merch, 2);

        $this->actingAs($this->manager())->get(route('designations.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('records.0.code', '201') // General 1
                ->where('records.1.code', '203') // General 5
                ->where('records.2.code', '301') // Merchandising 1: serials restart in every category
                ->where('records.3.code', '302')
                ->where('records.2.designation_category_id', $merch->id));
    }

    public function test_the_same_title_and_serial_can_sit_in_two_categories(): void
    {
        $general = $this->designationCategory();
        $merch = $this->designationCategory('Merchandising');
        $this->designation('201', 'Executive', $general, 1);

        $this->actingAs($this->manager())
            ->post(route('designations.store'), ['designation_category_id' => $merch->id, 'code' => '301', 'title' => 'Executive', 'serial' => 1, 'is_active' => true])
            ->assertRedirect(route('designations.index'))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Designation::where('title', 'Executive')->count());
        $this->assertSame($merch->id, Designation::firstWhere('code', '301')->designation_category_id);
    }

    // ----- a designation needs a real category -------------------------------------

    public function test_a_designation_must_have_an_existing_category(): void
    {
        $user = $this->manager();

        $this->actingAs($user)->post(route('designations.store'), ['code' => '201', 'title' => 'Executive', 'serial' => 1])
            ->assertSessionHasErrors('designation_category_id');
        $this->actingAs($user)->post(route('designations.store'), ['designation_category_id' => 9999, 'code' => '201', 'title' => 'Executive', 'serial' => 1])
            ->assertSessionHasErrors('designation_category_id');

        $this->assertSame(0, Designation::count());
    }

    public function test_a_designation_can_be_moved_to_another_category(): void
    {
        $general = $this->designationCategory();
        $merch = $this->designationCategory('Merchandising');
        $designation = $this->designation('201', 'Merchandiser', $general, 1);

        $this->actingAs($this->manager())
            ->put(route('designations.update', $designation), ['designation_category_id' => $merch->id, 'code' => '201', 'title' => 'Merchandiser', 'serial' => 1, 'is_active' => true])
            ->assertSessionHasNoErrors();

        $this->assertSame($merch->id, $designation->fresh()->designation_category_id);
    }

    // ----- adding, renaming and deleting categories -------------------------------

    public function test_a_category_can_be_added_renamed_and_deleted(): void
    {
        $user = $this->manager();

        $this->actingAs($user)->post(route('designations.categories.store'), ['name' => '  Machine   Operators '])
            ->assertRedirect(route('designations.index'))
            ->assertSessionHas('success');
        $category = DesignationCategory::firstWhere('name', 'Machine Operators'); // spacing tidied
        $this->assertNotNull($category);

        $this->actingAs($user)->put(route('designations.categories.update', $category), ['name' => 'Machine Men'])
            ->assertRedirect(route('designations.index'));
        $this->assertSame('Machine Men', $category->fresh()->name);

        $this->actingAs($user)->delete(route('designations.categories.destroy', $category))
            ->assertRedirect(route('designations.index'))
            ->assertSessionHas('success');
        $this->assertNull(DesignationCategory::find($category->id));
    }

    public function test_category_names_must_be_filled_in_and_unique_ignoring_case(): void
    {
        $user = $this->manager();
        $merch = $this->designationCategory('Merchandising');
        $other = $this->designationCategory('Machine Operators');

        $this->actingAs($user)->post(route('designations.categories.store'), ['name' => ''])->assertSessionHasErrors('name');
        $this->actingAs($user)->post(route('designations.categories.store'), ['name' => ' MERCHANDISING '])->assertSessionHasErrors('name');
        $this->actingAs($user)->put(route('designations.categories.update', $other), ['name' => 'merchandising'])->assertSessionHasErrors('name');
        // Saving a category under its own name (or only changing its case) is fine.
        $this->actingAs($user)->put(route('designations.categories.update', $merch), ['name' => 'MERCHANDISING'])->assertSessionHasNoErrors();

        $this->assertSame('MERCHANDISING', $merch->fresh()->name);
    }

    public function test_a_category_that_holds_designations_cannot_be_deleted(): void
    {
        $merch = $this->designationCategory('Merchandising');
        $this->designation('301', 'Merchandiser', $merch, 1);

        $this->actingAs($this->manager())->delete(route('designations.categories.destroy', $merch))
            ->assertRedirect(route('designations.index'))
            ->assertSessionHas('error');

        $this->assertNotNull(DesignationCategory::find($merch->id));
        $this->assertSame(1, Designation::count());
    }

    public function test_the_database_also_refuses_to_lose_a_category_that_designations_use(): void
    {
        $merch = $this->designationCategory('Merchandising');
        $this->designation('301', 'Merchandiser', $merch, 1);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $merch->delete();
    }

    // ----- who may do what ------------------------------------------------------------

    public function test_categories_follow_the_designations_permissions(): void
    {
        $category = $this->designationCategory('Merchandising');

        $viewer = $this->userWith(['view designations']);
        $this->actingAs($viewer)->post(route('designations.categories.store'), ['name' => 'X'])->assertForbidden();
        $this->actingAs($viewer)->put(route('designations.categories.update', $category), ['name' => 'X'])->assertForbidden();
        $this->actingAs($viewer)->delete(route('designations.categories.destroy', $category))->assertForbidden();

        $adder = $this->userWith(['view designations', 'create designations']);
        $this->actingAs($adder)->post(route('designations.categories.store'), ['name' => 'Staff'])->assertSessionHasNoErrors()->assertRedirect();
        $this->actingAs($adder)->put(route('designations.categories.update', $category), ['name' => 'X'])->assertForbidden();
        $this->actingAs($adder)->delete(route('designations.categories.destroy', $category))->assertForbidden();

        $this->assertDatabaseHas('designation_categories', ['name' => 'Staff']);
        $this->assertSame('Merchandising', $category->fresh()->name);
    }

    public function test_the_page_tells_what_the_person_may_do_with_categories(): void
    {
        $this->actingAs($this->userWith(['view designations', 'create designations']))->get(route('designations.index'))
            ->assertInertia(fn (Assert $page) => $page->where('can.create', true)->where('can.edit', false)->where('can.delete', false));
    }

    // ----- registration ---------------------------------------------------------------

    public function test_the_registration_list_carries_each_designations_category_in_category_order(): void
    {
        $general = $this->designationCategory();
        $merch = $this->designationCategory('Merchandising');
        $this->designation('302', 'Senior Merchandiser', $merch, 2);
        $this->designation('301', 'Merchandiser', $merch, 1);
        $this->designation('201', 'Executive', $general, 1);
        $this->designation('202', 'Retired', $general, 2, active: false);

        $this->get('/register')->assertInertia(fn (Assert $page) => $page
            ->has('designations', 3)
            ->where('designations.0.title', 'Executive')
            ->where('designations.0.category', 'General')
            ->where('designations.1.title', 'Merchandiser')
            ->where('designations.1.category', 'Merchandising')
            ->where('designations.2.title', 'Senior Merchandiser'));
    }

    // ----- Excel import -----------------------------------------------------------------

    public function test_the_import_takes_each_designations_category_by_name(): void
    {
        $this->designationCategory();
        $merch = $this->designationCategory('Merchandising');

        $this->actingAs($this->userWith(['view designations', 'manage designations', 'import designations']))
            ->post(route('designations.import'), ['file' => $this->csv("Code,Title,Category,Serial,Active\n"
                ."301,Merchandiser,merchandising,1,Yes\n"
                ."201,Executive,General,1,Yes\n")])
            ->assertSessionHas('import_result', fn ($r) => $r['created_count'] === 2 && $r['error_count'] === 0);

        $this->assertSame($merch->id, Designation::firstWhere('code', '301')->designation_category_id);
        $this->assertSame($this->designationCategory()->id, Designation::firstWhere('code', '201')->designation_category_id);
    }

    public function test_an_import_row_with_an_unknown_or_missing_category_is_refused(): void
    {
        $this->designationCategory();
        $user = $this->userWith(['view designations', 'manage designations', 'import designations']);

        $this->actingAs($user)
            ->post(route('designations.import'), ['file' => $this->csv("Code,Title,Category,Serial,Active\n"
                ."301,Merchandiser,Merchandisng,1,Yes\n"
                ."302,Cutter,,2,Yes\n")])
            ->assertSessionHas('import_result', fn ($r) => $r['created_count'] === 0 && $r['error_count'] === 2);

        $this->assertSame(0, Designation::count());
    }

    public function test_a_blank_category_in_the_import_keeps_a_saved_designations_category(): void
    {
        $this->designationCategory();
        $merch = $this->designationCategory('Merchandising');
        $designation = $this->designation('301', 'Merchandiser', $merch, 1);

        $this->actingAs($this->userWith(['view designations', 'manage designations', 'import designations']))
            ->post(route('designations.import'), ['file' => $this->csv("Code,Title,Category,Serial,Active\n301,Merchandiser,,4,Yes\n")])
            ->assertSessionHas('import_result', fn ($r) => $r['updated_count'] === 1 && $r['error_count'] === 0);

        $designation->refresh();
        $this->assertSame($merch->id, $designation->designation_category_id);
        $this->assertSame(4, $designation->serial);
    }
}
