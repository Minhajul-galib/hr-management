<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * RolePermissionSeeder creates demo accounts with a known password and deletes
 * every other user. Production must only ever get its roles and permissions.
 */
class ProductionSeedingTest extends TestCase
{
    use RefreshDatabase;

    /** db:seed asks for confirmation in production; a real deploy passes --force. */
    private function seedProduction(): void
    {
        $this->artisan('db:seed', ['--class' => RolePermissionSeeder::class, '--force' => true])->assertSuccessful();
    }

    public function test_in_production_only_roles_and_permissions_are_seeded(): void
    {
        $this->app['env'] = 'production';
        $employee = User::factory()->create(['email' => 'real.employee@company.test']);

        $this->seedProduction();

        // Real people are untouched, and no known-password account appears.
        $this->assertNotNull($employee->fresh());
        $this->assertSame(1, User::withTrashed()->count());
        $this->assertDatabaseMissing('users', ['email' => 'admin@erp.com']);
        $this->assertDatabaseMissing('users', ['email' => 'admin@example.com']);

        // ...but the access model is in place.
        $this->assertTrue(Role::where('name', 'Super System Admin')->exists());
        $this->assertTrue(Role::where('name', 'Editor')->exists());
        $this->assertTrue(Permission::where('name', 'manage holidays')->exists());
        $this->assertTrue(Role::findByName('Editor')->hasPermissionTo('manage departments'));
    }

    public function test_seeding_again_in_production_is_harmless(): void
    {
        $this->app['env'] = 'production';
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('Super System Admin'));

        $this->seedProduction();
        $this->seedProduction();

        $this->assertTrue($admin->fresh()->hasRole('Super System Admin'));
        $this->assertSame(1, User::count());
    }

    public function test_outside_production_the_demo_accounts_are_still_created(): void
    {
        // Dev and test behaviour is unchanged.
        $this->seed(RolePermissionSeeder::class);

        $admin = User::where('email', 'admin@erp.com')->first();
        $this->assertNotNull($admin);
        $this->assertTrue($admin->hasRole('Super System Admin'));
    }
}
