<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\User;
use App\Support\Holidays\HolidayCalendar;
use App\Support\MasterData\MasterDataRegistry;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Role authorization for all master data: every master (and Holidays) has a
 * permission per action — view, create, edit, delete, import, export — and
 * `manage`, which is full access. This checks each one unlocks its own action
 * and nothing else, that the role matrix lists them, and the migration that
 * introduced them.
 */
class MasterDataPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_11_21_000004_add_master_data_action_permissions.php';

    private function userWith(array $permissions): User
    {
        $role = Role::create(['name' => 'Role '.uniqid()]);
        $role->givePermissionTo(array_map(fn ($p) => Permission::findOrCreate($p), $permissions));

        $user = User::factory()->create();
        $user->syncRoles([$role]);

        return $user;
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $user->syncRoles([Role::findOrCreate('Super System Admin')]);

        return $user;
    }

    /** A request is allowed through when it is anything but 403 (an empty form fails validation, an unknown id is a 404). */
    private function assertAllowed(\Illuminate\Testing\TestResponse $response, string $what): void
    {
        $this->assertNotSame(403, $response->getStatusCode(), "{$what} was refused");
    }

    private function assertRefused(\Illuminate\Testing\TestResponse $response, string $what): void
    {
        $this->assertSame(403, $response->getStatusCode(), "{$what} was allowed");
    }

    /** @return array<string, array{0: string}> */
    public static function masters(): array
    {
        return array_combine(MasterDataRegistry::keys(), array_map(fn ($key) => [$key], MasterDataRegistry::keys()));
    }

    /**
     * One request per action, each as the given user.
     *
     * @return array<string, \Closure(User): \Illuminate\Testing\TestResponse>
     */
    private function requests(string $key): array
    {
        $requests = [
            'create' => fn (User $u) => $this->actingAs($u)->post(route("{$key}.store"), []),
            'edit' => fn (User $u) => $this->actingAs($u)->put(route("{$key}.update", 999999), []),
            'delete' => fn (User $u) => $this->actingAs($u)->delete(route("{$key}.destroy", 999999)),
        ];

        if (isset(MasterDataRegistry::find($key)['import'])) {
            $requests += [
                'export' => fn (User $u) => $this->actingAs($u)->get(route("{$key}.export")),
                'import' => fn (User $u) => $this->actingAs($u)->get(route("{$key}.template")),
            ];
        }

        return $requests;
    }

    // ----- each permission unlocks its own action ------------------------------------

    #[DataProvider('masters')]
    public function test_each_permission_unlocks_only_its_own_action(string $key): void
    {
        $permission = MasterDataRegistry::find($key)['permission'];
        $requests = $this->requests($key);

        foreach (array_keys($requests) as $action) {
            $user = $this->userWith(["{$action} {$permission}"]);

            foreach ($requests as $other => $request) {
                $response = $request($user);

                $other === $action
                    ? $this->assertAllowed($response, "{$key}: {$other} with only '{$action} {$permission}'")
                    : $this->assertRefused($response, "{$key}: {$other} with only '{$action} {$permission}'");
            }
        }
    }

    #[DataProvider('masters')]
    public function test_full_access_allows_every_action(string $key): void
    {
        $user = $this->userWith(['manage '.MasterDataRegistry::find($key)['permission']]);

        foreach ($this->requests($key) as $action => $request) {
            $this->assertAllowed($request($user), "{$key}: {$action} with full access");
        }
        $this->actingAs($user)->get(route("{$key}.index"))->assertOk();
    }

    #[DataProvider('masters')]
    public function test_no_permission_allows_nothing_and_view_alone_changes_nothing(string $key): void
    {
        $permission = MasterDataRegistry::find($key)['permission'];
        $nobody = User::factory()->create();
        $viewer = $this->userWith(["view {$permission}"]);

        $this->actingAs($nobody)->get(route("{$key}.index"))->assertForbidden();
        foreach ($this->requests($key) as $action => $request) {
            $this->assertRefused($request($nobody), "{$key}: {$action} with no permission");
            $this->assertRefused($request($viewer), "{$key}: {$action} with only view");
        }
    }

    #[DataProvider('masters')]
    public function test_any_one_permission_opens_the_screen(string $key): void
    {
        foreach (MasterDataRegistry::opening(MasterDataRegistry::find($key)) as $name) {
            $this->actingAs($this->userWith([$name]))->get(route("{$key}.index"))->assertOk();
        }
    }

    // ----- the page shows only what the role may do ---------------------------------

    public function test_the_page_is_told_what_the_role_may_do(): void
    {
        $user = $this->userWith(['view departments', 'edit departments', 'export departments']);

        $this->actingAs($user)->get(route('departments.index'))->assertInertia(fn (Assert $page) => $page
            ->where('can', ['create' => false, 'edit' => true, 'delete' => false, 'import' => false, 'export' => true]));

        $this->actingAs($this->userWith(['manage departments']))->get(route('departments.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('can', ['create' => true, 'edit' => true, 'delete' => true, 'import' => true, 'export' => true]));
    }

    public function test_a_master_without_excel_has_no_import_or_export(): void
    {
        $this->assertArrayNotHasKey('import', MasterDataRegistry::find('acr-templates'));
        $this->assertFalse(Route::has('acr-templates.export'));
        $this->assertFalse(Route::has('acr-templates.template'));
        $this->assertNotContains('import acr templates', MasterDataRegistry::permissionNames());
        $this->assertNotContains('export acr templates', MasterDataRegistry::permissionNames());

        $this->actingAs($this->userWith(['manage acr templates']))->get(route('acr-templates.index'))
            ->assertInertia(fn (Assert $page) => $page->where('can.import', false)->where('can.export', false)->where('can.create', true));
    }

    public function test_every_excel_step_needs_the_import_permission(): void
    {
        $everythingButImport = $this->userWith(['view departments', 'create departments', 'edit departments', 'delete departments', 'export departments']);

        foreach (['departments.template' => 'get', 'departments.import' => 'post', 'departments.import.preview' => 'post', 'departments.import.check' => 'post'] as $name => $method) {
            $this->assertRefused($this->actingAs($everythingButImport)->{$method}(route($name)), $name);
        }

        $importer = $this->userWith(['import departments']);
        foreach (['departments.import' => 'post', 'departments.import.preview' => 'post', 'departments.import.check' => 'post'] as $name => $method) {
            $this->assertAllowed($this->actingAs($importer)->{$method}(route($name)), $name);
        }
        $this->actingAs($importer)->get(route('departments.template'))->assertOk();
        // Importing does not let you read the list out as a file.
        $this->assertRefused($this->actingAs($importer)->get(route('departments.export')), 'export with only import');
    }

    // ----- the role matrix ---------------------------------------------------------------

    public function test_the_matrix_lists_every_permission_of_every_master_in_sidebar_order(): void
    {
        $this->actingAs($this->superAdmin())->get(route('roles.index'))
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $matrix = $page->toArray()['props']['masterData'];
                $labels = array_column($matrix, 'label');

                // Departments, Units, then the rest, then Holidays — as in the sidebar.
                $this->assertSame(['Departments', 'Units', 'Designations'], array_slice($labels, 0, 3));
                $this->assertSame('Holidays', end($labels));
                $this->assertCount(count(MasterDataRegistry::keys()) + 2, $matrix);

                $byLabel = array_column($matrix, null, 'label');
                $actions = fn (string $label) => array_column($byLabel[$label]['actions'], 'action');

                $this->assertSame(['view', 'create', 'edit', 'delete', 'import', 'export'], $actions('Departments'));
                $this->assertSame(['view', 'create', 'edit', 'delete'], $actions('ACR Templates'));
                $this->assertSame(['view', 'create', 'edit', 'delete'], $actions('Units'));
                $this->assertSame(['view', 'create', 'edit', 'delete', 'import'], $actions('Holidays'));
                $this->assertSame('manage holidays', $byLabel['Holidays']['manage']);

                // A unit's Excel import is part of adding units, and the matrix says so.
                $this->assertStringContainsString('Excel import', $byLabel['Units']['actions'][1]['label']);
            });
    }

    public function test_every_checkbox_in_the_matrix_is_a_real_permission(): void
    {
        $response = $this->actingAs($this->superAdmin())->get(route('roles.index'))->assertOk();

        $response->assertInertia(function (Assert $page) {
            foreach ($page->toArray()['props']['masterData'] as $master) {
                foreach ([...array_column($master['actions'], 'permission'), $master['manage']] as $name) {
                    $this->assertTrue(Permission::where('name', $name)->exists(), "the matrix lists '{$name}' but it does not exist");
                }
            }
        });
    }

    public function test_ticking_boxes_in_the_matrix_grants_exactly_those_actions(): void
    {
        $role = Role::create(['name' => 'Designation clerk']);
        $clerk = User::factory()->create();
        $clerk->syncRoles([$role]);

        $this->actingAs($this->superAdmin())->patch(route('roles.permissions.update', $role), [
            'permissions' => ['view designations', 'create designations', 'export designations'],
        ])->assertSessionHasNoErrors();

        $this->assertAllowed($this->actingAs($clerk)->post(route('designations.store'), []), 'create');
        $this->assertAllowed($this->actingAs($clerk)->get(route('designations.export')), 'export');
        $this->assertRefused($this->actingAs($clerk)->put(route('designations.update', 1), []), 'edit');
        $this->assertRefused($this->actingAs($clerk)->delete(route('designations.destroy', 1)), 'delete');

        // Untick Add in the matrix and it stops working straight away.
        $this->actingAs($this->superAdmin())->patch(route('roles.permissions.update', $role), [
            'permissions' => ['view designations'],
        ])->assertSessionHasNoErrors();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertRefused($this->actingAs($clerk->fresh())->post(route('designations.store'), []), 'create after unticking');
    }

    public function test_the_super_admin_can_do_everything_on_every_master(): void
    {
        $admin = $this->superAdmin();

        foreach (MasterDataRegistry::keys() as $key) {
            $this->actingAs($admin)->get(route("{$key}.index"))->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('can.create', true)->where('can.edit', true)->where('can.delete', true));
            foreach ($this->requests($key) as $action => $request) {
                $this->assertAllowed($request($admin), "{$key}: {$action}");
            }
        }
    }

    // ----- Holidays ------------------------------------------------------------------------

    private function holiday(): CalendarEvent
    {
        return CalendarEvent::create([
            'user_id' => User::factory()->create()->id,
            'title' => 'Victory Day',
            'description' => null,
        ] + HolidayCalendar::attributes(Carbon::parse('2026-12-16'), null));
    }

    /** @return array<string, \Closure(User, CalendarEvent): \Illuminate\Testing\TestResponse> */
    private function holidayRequests(): array
    {
        return [
            'create' => fn (User $u, CalendarEvent $h) => $this->actingAs($u)->post(route('calendar.holidays.store'), []),
            'edit' => fn (User $u, CalendarEvent $h) => $this->actingAs($u)->put(route('calendar.holidays.update', $h), []),
            'delete' => fn (User $u, CalendarEvent $h) => $this->actingAs($u)->delete(route('calendar.holidays.destroy', $h)),
            'import' => fn (User $u, CalendarEvent $h) => $this->actingAs($u)->post(route('calendar.holidays.import'), []),
        ];
    }

    public function test_each_holiday_permission_unlocks_only_its_own_action(): void
    {
        foreach (array_keys($this->holidayRequests()) as $action) {
            $user = $this->userWith(["{$action} holidays"]);

            foreach ($this->holidayRequests() as $other => $request) {
                $response = $request($user, $this->holiday());

                $other === $action
                    ? $this->assertAllowed($response, "holidays: {$other} with only '{$action} holidays'")
                    : $this->assertRefused($response, "holidays: {$other} with only '{$action} holidays'");
            }
        }
    }

    public function test_the_weekly_off_days_need_the_edit_permission(): void
    {
        $body = ['weekday_off' => [5]];

        $this->assertRefused($this->actingAs($this->userWith(['create holidays', 'delete holidays', 'import holidays']))
            ->post(route('calendar.holidays.settings.update'), $body), 'weekly off with no edit');
        $this->actingAs($this->userWith(['edit holidays']))->post(route('calendar.holidays.settings.update'), $body)->assertSessionHasNoErrors();
    }

    public function test_full_access_allows_every_holiday_action(): void
    {
        $manager = $this->userWith(['manage holidays']);

        foreach ($this->holidayRequests() as $action => $request) {
            $this->assertAllowed($request($manager, $this->holiday()), "holidays: {$action} with full access");
        }
        $this->actingAs($manager)->get(route('calendar.holidays.template'))->assertOk();
        $this->actingAs($manager)->get(route('calendar.holidays.setup'))->assertOk();
    }

    public function test_the_holidays_page_opens_for_any_holiday_permission_and_shows_only_what_is_allowed(): void
    {
        $this->actingAs(User::factory()->create())->get(route('calendar.holidays.setup'))->assertForbidden();

        $this->actingAs($this->userWith(['view holidays']))->get(route('calendar.holidays.setup'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('can', ['create' => false, 'edit' => false, 'delete' => false, 'import' => false]));

        $this->actingAs($this->userWith(['view holidays', 'delete holidays', 'import holidays']))->get(route('calendar.holidays.setup'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('can', ['create' => false, 'edit' => false, 'delete' => true, 'import' => true]));

        $this->actingAs($this->userWith(['manage holidays']))->get(route('calendar.holidays.setup'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('can', ['create' => true, 'edit' => true, 'delete' => true, 'import' => true]));
    }

    public function test_the_calendar_follows_the_holiday_permissions_too(): void
    {
        $holiday = $this->holiday();

        $editor = $this->userWith(['edit holidays']);
        $this->assertAllowed($this->actingAs($editor)->patch(route('calendar.events.update', $holiday), ['title' => 'Victory Day (renamed)']), 'calendar edit');
        $this->assertRefused($this->actingAs($editor)->delete(route('calendar.events.destroy', $holiday)), 'calendar delete with only edit');

        $deleter = $this->userWith(['delete holidays']);
        $this->assertRefused($this->actingAs($deleter)->patch(route('calendar.events.update', $holiday), ['title' => 'x']), 'calendar edit with only delete');
        $this->actingAs($deleter)->delete(route('calendar.events.destroy', $holiday))->assertSessionHasNoErrors();
        $this->assertNull(CalendarEvent::find($holiday->id));
    }

    // ----- the migration -----------------------------------------------------------------------

    public function test_the_migration_creates_every_permission(): void
    {
        foreach (MasterDataRegistry::allPermissionNames() as $name) {
            $this->assertTrue(Permission::where('name', $name)->exists(), "missing {$name}");
        }
    }

    public function test_the_migration_lets_existing_viewers_keep_exporting_and_gives_nobody_more(): void
    {
        $viewer = Role::create(['name' => 'Department viewer']);
        $viewer->givePermissionTo('view departments');
        $manager = Role::create(['name' => 'Department manager']);
        $manager->givePermissionTo('manage departments');

        (require base_path(self::MIGRATION))->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertEqualsCanonicalizing(['view departments', 'export departments'], $viewer->fresh()->permissions->pluck('name')->all());
        $this->assertSame(['manage departments'], $manager->fresh()->permissions->pluck('name')->all());
    }

    public function test_the_migration_gives_the_editor_every_master_but_not_holidays(): void
    {
        $editor = Role::findOrCreate('Editor');
        $editor->givePermissionTo('manage designations');

        (require base_path(self::MIGRATION))->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (MasterDataRegistry::permissionNames() as $name) {
            $this->assertTrue($editor->fresh()->hasPermissionTo($name), "Editor lacks {$name}");
        }
        $this->assertFalse($editor->fresh()->hasPermissionTo('view holidays'));
    }

    public function test_rolling_the_migration_back_removes_only_what_it_added(): void
    {
        (require base_path(self::MIGRATION))->down();

        foreach (['create departments', 'edit departments', 'delete departments', 'import departments', 'export departments', 'view holidays', 'create holidays', 'import holidays'] as $name) {
            $this->assertFalse(Permission::where('name', $name)->exists(), "{$name} should be gone");
        }
        foreach (['view departments', 'manage departments', 'manage holidays', 'view designations'] as $name) {
            $this->assertTrue(Permission::where('name', $name)->exists(), "{$name} should stay");
        }
    }
}
