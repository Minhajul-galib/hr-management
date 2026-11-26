<?php

namespace Tests\Feature\Auth;

use App\Models\Designation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'phone' => '+8801712345678',
            'password' => 'password',
            'password_confirmation' => 'password',
        ], $overrides);
    }

    public function test_registration_screen_can_be_rendered(): void
    {
        $this->get('/register')->assertStatus(200);
    }

    public function test_registration_screen_lists_only_active_designations(): void
    {
        Designation::create(['designation_category_id' => $this->designationCategory()->id, 'code' => 'mgr', 'title' => 'Manager', 'serial' => 2]);
        Designation::create(['designation_category_id' => $this->designationCategory()->id, 'code' => 'dir', 'title' => 'Director', 'serial' => 1]);
        Designation::create(['designation_category_id' => $this->designationCategory()->id, 'code' => 'old', 'title' => 'Retired Post', 'serial' => 3, 'is_active' => false]);

        $this->get('/register')->assertInertia(fn (Assert $page) => $page
            ->component('Auth/Register')
            ->has('designations', 2)
            ->where('designations.0.title', 'Director') // most senior (lowest serial) first
            ->where('designations.1.title', 'Manager'));
    }

    public function test_new_users_can_register_as_applicant_with_a_designation(): void
    {
        $designation = Designation::create(['designation_category_id' => $this->designationCategory()->id, 'code' => 'mgr', 'title' => 'Manager']);

        $response = $this->post('/register', $this->payload(['designation_id' => $designation->id]));

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));

        $user = User::where('email', 'test@example.com')->firstOrFail();
        $this->assertSame($designation->id, $user->designation_id);
        $this->assertSame(['Applicant'], $user->getRoleNames()->all());
    }

    public function test_designation_is_required_once_designations_exist(): void
    {
        Designation::create(['designation_category_id' => $this->designationCategory()->id, 'code' => 'mgr', 'title' => 'Manager']);

        $this->post('/register', $this->payload())->assertSessionHasErrors('designation_id');
        $this->assertGuest();
    }

    public function test_an_inactive_or_unknown_designation_is_rejected(): void
    {
        $inactive = Designation::create(['designation_category_id' => $this->designationCategory()->id, 'code' => 'old', 'title' => 'Retired Post', 'is_active' => false]);
        Designation::create(['designation_category_id' => $this->designationCategory()->id, 'code' => 'mgr', 'title' => 'Manager']);

        $this->post('/register', $this->payload(['designation_id' => $inactive->id]))
            ->assertSessionHasErrors('designation_id');
        $this->post('/register', $this->payload(['designation_id' => 9999]))
            ->assertSessionHasErrors('designation_id');
        $this->assertGuest();
    }

    public function test_registration_works_while_the_designation_list_is_empty(): void
    {
        $this->post('/register', $this->payload())->assertSessionHasNoErrors();

        $this->assertAuthenticated();
        $this->assertNull(User::where('email', 'test@example.com')->value('designation_id'));
    }

    public function test_a_new_applicant_sees_the_thank_you_page_with_email_and_designation(): void
    {
        $designation = Designation::create(['designation_category_id' => $this->designationCategory()->id, 'code' => 'mgr', 'title' => 'Manager']);

        $this->post('/register', $this->payload(['designation_id' => $designation->id]));

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Applicant')
                ->where('applicant.name', 'Test User')
                ->where('applicant.email', 'test@example.com')
                ->where('applicant.designation', 'Manager'));
    }

    public function test_an_applicant_without_a_designation_still_sees_the_page(): void
    {
        $user = User::factory()->create(['email' => 'early@example.com']); // observer gives Applicant

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Applicant')
                ->where('applicant.email', 'early@example.com')
                ->where('applicant.designation', null));
    }

    public function test_an_applicant_granted_view_dashboard_gets_the_real_dashboard(): void
    {
        $role = Role::findOrCreate('Applicant');
        $role->givePermissionTo(Permission::findOrCreate('view dashboard'));

        $this->actingAs(User::factory()->create())->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Dashboard/Index'));
    }

    public function test_a_user_who_is_not_an_applicant_and_cannot_view_the_dashboard_is_still_forbidden(): void
    {
        $user = User::factory()->create();
        $user->syncRoles([Role::findOrCreate('Top Management')]);

        $this->actingAs($user)->get(route('dashboard'))->assertForbidden();
    }
}
