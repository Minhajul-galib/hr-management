<?php

namespace Tests\Feature;

use App\Models\Audit;
use App\Models\AuditFinding;
use App\Models\Unit;
use App\Models\User;
use App\Support\AuditPermissions;
use App\Support\AuditTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Role authorization for Compliance → Internal Audit: every action (view, create, edit,
 * delete, complete, reopen, update the corrective action plan) has its own permission and
 * `manage audits` is full access. This checks each one unlocks its own action and nothing
 * else, that the role matrix lists them, and the migration that introduced them.
 */
class AuditPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_11_22_000002_add_audit_action_permissions.php';

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->unit = Unit::create([
            'unit_code' => 'U000001',
            'name' => 'Clifton Apparels Ltd',
            'location' => 'Baizid Complex',
            'is_active' => true,
        ]);
    }

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

    /** A request is allowed through when it is anything but 403 (an empty form fails validation instead). */
    private function assertAllowed(TestResponse $response, string $what): void
    {
        $this->assertNotSame(403, $response->getStatusCode(), "{$what} was refused");
    }

    private function assertRefused(TestResponse $response, string $what): void
    {
        $this->assertSame(403, $response->getStatusCode(), "{$what} was allowed");
    }

    private function audit(bool $completed = false): Audit
    {
        $audit = Audit::create([
            'unit_id' => $this->unit->id,
            'facility_name' => $this->unit->name,
            'assessment_date' => '2026-09-15',
            'assessment_standard' => 'Social Compliance',
            'audit_type' => 'initial',
            'auditors' => ['Farhana Rahman'],
        ]);

        if ($completed) {
            $audit->forceFill(['status' => Audit::STATUS_COMPLETED, 'completed_at' => now()])->save();
        }

        return $audit;
    }

    /** An open, non-compliant finding on a completed audit: the one kind whose plan can be updated. */
    private function finding(): AuditFinding
    {
        return $this->audit(completed: true)->findings()->create([
            'category' => 'Occupational Health & Safety',
            'rating' => 'major',
            'finding' => 'Fire exit blocked.',
            'status' => AuditFinding::STATUS_OPEN,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'action' => 'draft',
            'unit_id' => $this->unit->id,
            'assessment_date' => '2026-09-15',
            'assessment_standard' => 'Social Compliance',
            'audit_type' => 'initial',
            'auditors' => ['Farhana Rahman'],
            'manpower' => AuditTemplate::manpower(),
            'floor_layout' => AuditTemplate::floorLayout(),
            'legal_documents' => AuditTemplate::legalDocuments(),
            'fire_equipment' => AuditTemplate::fireEquipment(),
            'findings' => [],
        ], $overrides);
    }

    /**
     * What each permission is for: a fresh record per request, as the given user.
     *
     * @return array<string, list<\Closure(User): TestResponse>>
     */
    private function requests(): array
    {
        return [
            'create' => [
                fn (User $u) => $this->actingAs($u)->get(route('audits.create')),
                fn (User $u) => $this->actingAs($u)->post(route('audits.store'), []),
            ],
            'edit' => [
                fn (User $u) => $this->actingAs($u)->get(route('audits.edit', $this->audit())),
                fn (User $u) => $this->actingAs($u)->put(route('audits.update', $this->audit()), []),
            ],
            'delete' => [
                fn (User $u) => $this->actingAs($u)->delete(route('audits.destroy', $this->audit())),
            ],
            'reopen' => [
                fn (User $u) => $this->actingAs($u)->post(route('audits.reopen', $this->audit(completed: true))),
            ],
            'cap' => [
                fn (User $u) => $this->actingAs($u)->patch(route('audits.findings.cap', $this->finding()), ['status' => 'open']),
            ],
        ];
    }

    // ----- each permission unlocks its own action ------------------------------------

    public function test_each_permission_unlocks_only_its_own_action(): void
    {
        foreach (array_keys($this->requests()) as $action) {
            $user = $this->userWith([AuditPermissions::permission($action)]);

            foreach ($this->requests() as $other => $requests) {
                foreach ($requests as $request) {
                    $response = $request($user);

                    $other === $action
                        ? $this->assertAllowed($response, "{$other} with only '".AuditPermissions::permission($action)."'")
                        : $this->assertRefused($response, "{$other} with only '".AuditPermissions::permission($action)."'");
                }
            }
        }
    }

    public function test_full_access_allows_every_action(): void
    {
        $user = $this->userWith([AuditPermissions::MANAGE]);

        foreach ($this->requests() as $action => $requests) {
            foreach ($requests as $request) {
                $this->assertAllowed($request($user), "{$action} with full access");
            }
        }
        $this->actingAs($user)->get(route('audits.index'))->assertOk();
    }

    public function test_view_alone_reads_everything_and_changes_nothing(): void
    {
        $nobody = User::factory()->create();
        $viewer = $this->userWith(['view audits']);
        $audit = $this->audit();

        $this->actingAs($nobody)->get(route('audits.index'))->assertForbidden();
        $this->actingAs($nobody)->get(route('audits.show', $audit))->assertForbidden();

        $this->actingAs($viewer)->get(route('audits.index'))->assertOk();
        $this->actingAs($viewer)->get(route('audits.show', $audit))->assertOk();
        $this->actingAs($viewer)->get(route('audits.print', $audit))->assertOk();

        foreach ($this->requests() as $action => $requests) {
            foreach ($requests as $request) {
                $this->assertRefused($request($nobody), "{$action} with no permission");
                $this->assertRefused($request($viewer), "{$action} with only view");
            }
        }
    }

    public function test_any_one_permission_opens_the_module(): void
    {
        $audit = $this->audit();

        foreach (AuditPermissions::opening() as $name) {
            $user = $this->userWith([$name]);

            $this->actingAs($user)->get(route('audits.index'))->assertOk();
            $this->actingAs($user)->get(route('audits.show', $audit))->assertOk();
        }
    }

    public function test_the_super_admin_can_do_everything(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->get(route('audits.index'))->assertOk();
        foreach ($this->requests() as $action => $requests) {
            foreach ($requests as $request) {
                $this->assertAllowed($request($admin), $action);
            }
        }
    }

    // ----- completing locks the report, so it has its own permission -------------------

    public function test_saving_a_draft_does_not_need_the_complete_permission(): void
    {
        $writer = $this->userWith(['create audits', 'edit audits']);

        $this->actingAs($writer)->post(route('audits.store'), $this->payload())->assertSessionHasNoErrors();
        $audit = Audit::firstOrFail();
        $this->assertSame('draft', $audit->status);

        $this->actingAs($writer)->put(route('audits.update', $audit), $this->payload())->assertSessionHasNoErrors();
    }

    public function test_completing_an_audit_needs_the_complete_permission(): void
    {
        $writer = $this->userWith(['create audits', 'edit audits']);
        $draft = $this->audit();
        $complete = $this->payload(['action' => 'complete', 'overall_result' => 'satisfactory']);

        $this->assertRefused($this->actingAs($writer)->post(route('audits.store'), $complete), 'complete on create without complete');
        $this->assertRefused($this->actingAs($writer)->put(route('audits.update', $draft), $complete), 'complete on edit without complete');
        $this->assertSame(0, Audit::where('status', Audit::STATUS_COMPLETED)->count());

        $closer = $this->userWith(['create audits', 'edit audits', 'complete audits']);
        $this->actingAs($closer)->put(route('audits.update', $draft), $complete)->assertSessionHasNoErrors();
        $this->assertTrue($draft->fresh()->isCompleted());
        $this->assertSame($closer->id, $draft->fresh()->completed_by);

        $this->actingAs($closer)->post(route('audits.store'), $complete)->assertSessionHasNoErrors();
        $this->assertSame(2, Audit::where('status', Audit::STATUS_COMPLETED)->count());
    }

    public function test_the_complete_permission_alone_cannot_write_a_report(): void
    {
        $closer = $this->userWith(['complete audits']);

        $this->assertRefused(
            $this->actingAs($closer)->post(route('audits.store'), $this->payload(['action' => 'complete', 'overall_result' => 'satisfactory'])),
            'complete without create',
        );
        $this->assertRefused($this->actingAs($closer)->put(route('audits.update', $this->audit()), $this->payload()), 'edit without edit');
    }

    public function test_the_page_gets_the_permissions_it_hides_buttons_by(): void
    {
        $user = $this->userWith(['view audits', 'edit audits', 'complete audits']);

        $this->actingAs($user)->get(route('audits.index'))->assertInertia(fn (Assert $page) => $page
            ->where('auth.permissions', fn ($permissions) => collect($permissions)->sort()->values()->all() === ['complete audits', 'edit audits', 'view audits']));
    }

    // ----- the role matrix ---------------------------------------------------------------

    public function test_the_matrix_lists_every_audit_permission(): void
    {
        $this->actingAs($this->superAdmin())->get(route('roles.index'))
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $compliance = $page->toArray()['props']['compliance'];

                $this->assertCount(1, $compliance);
                $this->assertSame('Internal Audit', $compliance[0]['label']);
                $this->assertSame(
                    ['view', 'create', 'edit', 'delete', 'complete', 'reopen', 'cap'],
                    array_column($compliance[0]['actions'], 'action'),
                );
                $this->assertSame('manage audits', $compliance[0]['manage']);

                $listed = [...array_column($compliance[0]['actions'], 'permission'), $compliance[0]['manage']];
                $this->assertEqualsCanonicalizing(AuditPermissions::names(), $listed);
                foreach ($listed as $name) {
                    $this->assertTrue(Permission::where('name', $name)->exists(), "the matrix lists '{$name}' but it does not exist");
                }
            });
    }

    public function test_ticking_boxes_in_the_matrix_grants_exactly_those_actions(): void
    {
        $role = Role::create(['name' => 'Audit clerk']);
        $clerk = User::factory()->create();
        $clerk->syncRoles([$role]);

        $this->actingAs($this->superAdmin())->patch(route('roles.permissions.update', $role), [
            'permissions' => ['view audits', 'create audits', 'update audit cap'],
        ])->assertSessionHasNoErrors();

        $this->assertAllowed($this->actingAs($clerk)->get(route('audits.create')), 'create');
        $this->assertAllowed($this->actingAs($clerk)->patch(route('audits.findings.cap', $this->finding()), ['status' => 'open']), 'cap');
        $this->assertRefused($this->actingAs($clerk)->get(route('audits.edit', $this->audit())), 'edit');
        $this->assertRefused($this->actingAs($clerk)->delete(route('audits.destroy', $this->audit())), 'delete');
        $this->assertRefused($this->actingAs($clerk)->post(route('audits.reopen', $this->audit(completed: true))), 'reopen');

        // Untick Create in the matrix and it stops working straight away.
        $this->actingAs($this->superAdmin())->patch(route('roles.permissions.update', $role), [
            'permissions' => ['view audits'],
        ])->assertSessionHasNoErrors();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertRefused($this->actingAs($clerk->fresh())->get(route('audits.create')), 'create after unticking');
    }

    // ----- the migration -----------------------------------------------------------------------

    public function test_the_migration_creates_every_permission_for_the_super_admin(): void
    {
        foreach (AuditPermissions::names() as $name) {
            $this->assertTrue(Permission::where('name', $name)->exists(), "missing {$name}");
        }

        $admin = Role::findOrCreate('Super System Admin');
        (require base_path(self::MIGRATION))->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (AuditPermissions::names() as $name) {
            $this->assertTrue($admin->fresh()->hasPermissionTo($name), "Super System Admin lacks {$name}");
        }
    }

    public function test_the_migration_gives_existing_roles_nothing_new(): void
    {
        $viewer = Role::create(['name' => 'Audit viewer']);
        $viewer->givePermissionTo('view audits');
        $manager = Role::create(['name' => 'Audit manager']);
        $manager->givePermissionTo('manage audits');
        $hr = Role::create(['name' => 'Audit follow-up']);
        $hr->givePermissionTo(['view audits', 'update audit cap']);

        (require base_path(self::MIGRATION))->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertSame(['view audits'], $viewer->fresh()->permissions->pluck('name')->all());
        $this->assertSame(['manage audits'], $manager->fresh()->permissions->pluck('name')->all());
        $this->assertEqualsCanonicalizing(['view audits', 'update audit cap'], $hr->fresh()->permissions->pluck('name')->all());

        // ...and a role that held `manage audits` before the split still does all of it.
        foreach ($this->requests() as $action => $requests) {
            foreach ($requests as $request) {
                $user = User::factory()->create();
                $user->syncRoles([$manager]);
                $this->assertAllowed($request($user), "{$action} for a role with only manage audits");
            }
        }
    }

    public function test_rolling_the_migration_back_removes_only_what_it_added(): void
    {
        (require base_path(self::MIGRATION))->down();

        foreach (['create audits', 'edit audits', 'delete audits', 'complete audits', 'reopen audits'] as $name) {
            $this->assertFalse(Permission::where('name', $name)->exists(), "{$name} should be gone");
        }
        foreach (['view audits', 'manage audits', 'update audit cap'] as $name) {
            $this->assertTrue(Permission::where('name', $name)->exists(), "{$name} should stay");
        }
    }
}
