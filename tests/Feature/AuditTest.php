<?php

namespace Tests\Feature;

use App\Models\Audit;
use App\Models\AuditFinding;
use App\Models\Unit;
use App\Models\User;
use App\Support\AuditTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuditTest extends TestCase
{
    use RefreshDatabase;

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

    private function auditor(): User
    {
        return $this->userWith(['view audits', 'manage audits', 'update audit cap']);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'action' => 'draft',
            'unit_id' => $this->unit->id,
            'facility_address' => 'Baizid Complex, Chattogram',
            'number_of_employees' => 1200,
            'assessment_date' => '2026-09-15',
            'assessment_standard' => 'Social Compliance',
            'assessment_partner' => 'Internal Auditor (Self)',
            'audit_type' => 'initial',
            'auditors' => ['Farhana Rahman', ''],
            'manpower' => AuditTemplate::manpower(),
            'floor_layout' => AuditTemplate::floorLayout(),
            'legal_documents' => AuditTemplate::legalDocuments(),
            'fire_equipment' => AuditTemplate::fireEquipment(),
            'findings' => [
                [
                    'category' => 'Occupational Health & Safety',
                    'rating' => 'major',
                    'finding' => 'Fire exit on 2nd floor blocked by cartons.',
                    'corrective_action' => 'Clear the exit and add daily checks.',
                    'responsible_person' => 'Admin Manager',
                    'target_date' => '2026-10-01',
                ],
                [
                    'category' => 'Child Labor',
                    'rating' => 'compliant',
                    'finding' => 'No child labor found.',
                    'corrective_action' => 'should be discarded',
                ],
            ],
        ], $overrides);
    }

    private function completedAudit(User $auditor): Audit
    {
        $this->actingAs($auditor)->post(route('audits.store'), $this->payload([
            'action' => 'complete',
            'overall_result' => 'satisfactory',
        ]));

        return Audit::latest('id')->firstOrFail();
    }

    public function test_the_module_is_gated_by_audit_permissions(): void
    {
        $this->actingAs(User::factory()->create())->get(route('audits.index'))->assertForbidden();

        $viewer = $this->userWith(['view audits']);
        $this->actingAs($viewer)->get(route('audits.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Audits/Index'));
        $this->actingAs($viewer)->get(route('audits.create'))->assertForbidden();
        $this->actingAs($viewer)->post(route('audits.store'), $this->payload())->assertForbidden();
    }

    public function test_an_auditor_can_save_a_draft_with_findings(): void
    {
        $response = $this->actingAs($this->auditor())->post(route('audits.store'), $this->payload());

        $audit = Audit::firstOrFail();
        $response->assertRedirect(route('audits.edit', $audit));

        $this->assertSame('draft', $audit->status);
        $this->assertSame(sprintf('IA-2026-%04d', $audit->id), $audit->reference_no);
        $this->assertSame('Clifton Apparels Ltd', $audit->facility_name);
        $this->assertSame(['Farhana Rahman'], $audit->auditors);
        $this->assertCount(19, $audit->legal_documents);

        [$issue, $compliant] = $audit->findings()->orderBy('sort_order')->get();
        $this->assertSame('open', $issue->status);
        $this->assertSame('2026-10-01', $issue->target_date->toDateString());
        $this->assertNull($compliant->status);
        $this->assertNull($compliant->corrective_action);
    }

    public function test_completing_an_audit_requires_an_overall_result(): void
    {
        $auditor = $this->auditor();

        $this->actingAs($auditor)
            ->post(route('audits.store'), $this->payload(['action' => 'complete']))
            ->assertSessionHasErrors('overall_result');
        $this->assertSame(0, Audit::count());

        $audit = $this->completedAudit($auditor);
        $this->assertTrue($audit->isCompleted());
        $this->assertSame($auditor->id, $audit->completed_by);
        $this->assertNotNull($audit->completed_at);
    }

    public function test_updating_syncs_findings_without_touching_other_audits(): void
    {
        $auditor = $this->auditor();
        $this->actingAs($auditor)->post(route('audits.store'), $this->payload());
        $this->actingAs($auditor)->post(route('audits.store'), $this->payload());
        [$auditA, $auditB] = Audit::orderBy('id')->get();

        $keptFinding = $auditA->findings()->where('rating', 'major')->firstOrFail();
        $foreignFinding = $auditB->findings()->where('rating', 'major')->firstOrFail();

        $this->actingAs($auditor)->put(route('audits.update', $auditA), $this->payload([
            'findings' => [
                ['id' => $keptFinding->id, 'category' => 'Occupational Health & Safety', 'rating' => 'critical', 'finding' => 'Updated text'],
                ['id' => $foreignFinding->id, 'category' => 'Compensation', 'rating' => 'minor', 'finding' => 'Late overtime payment'],
            ],
        ]))->assertRedirect(route('audits.edit', $auditA));

        $this->assertSame(['critical', 'minor'], $auditA->findings()->orderBy('sort_order')->pluck('rating')->all());
        $this->assertSame('Updated text', $keptFinding->fresh()->finding);
        $this->assertSame($auditB->id, $foreignFinding->fresh()->audit_id);
        $this->assertSame('Fire exit on 2nd floor blocked by cartons.', $foreignFinding->fresh()->finding);
        $this->assertSame(2, $auditB->findings()->count());
    }

    public function test_completed_audits_are_locked_until_reopened(): void
    {
        $auditor = $this->auditor();
        $audit = $this->completedAudit($auditor);

        $this->actingAs($auditor)->get(route('audits.edit', $audit))->assertRedirect(route('audits.show', $audit));
        $this->actingAs($auditor)
            ->put(route('audits.update', $audit), $this->payload(['facility_address' => 'Changed']))
            ->assertSessionHas('error');
        $this->assertSame('Baizid Complex, Chattogram', $audit->fresh()->facility_address);

        $this->actingAs($auditor)->delete(route('audits.destroy', $audit))->assertSessionHas('error');
        $this->assertModelExists($audit);

        $this->actingAs($auditor)->post(route('audits.reopen', $audit))->assertRedirect(route('audits.edit', $audit));
        $this->assertSame('draft', $audit->fresh()->status);
        $this->assertNull($audit->fresh()->completed_at);

        $this->actingAs($auditor)->delete(route('audits.destroy', $audit))->assertRedirect(route('audits.index'));
        $this->assertModelMissing($audit);
        $this->assertSame(0, AuditFinding::count());
    }

    public function test_cap_updates_are_limited_to_completed_non_compliant_findings(): void
    {
        $auditor = $this->auditor();
        $hr = $this->userWith(['view audits', 'update audit cap']);
        $viewer = $this->userWith(['view audits']);

        $this->actingAs($auditor)->post(route('audits.store'), $this->payload());
        $draftFinding = AuditFinding::where('rating', 'major')->firstOrFail();
        $this->actingAs($hr)
            ->patch(route('audits.findings.cap', $draftFinding), ['status' => 'closed', 'corrective_action' => 'Done'])
            ->assertSessionHas('error');
        $this->assertSame('open', $draftFinding->fresh()->status);

        $audit = $this->completedAudit($auditor);
        $finding = $audit->findings()->where('rating', 'major')->firstOrFail();
        $compliant = $audit->findings()->where('rating', 'compliant')->firstOrFail();

        $this->actingAs($viewer)
            ->patch(route('audits.findings.cap', $finding), ['status' => 'closed', 'corrective_action' => 'Done'])
            ->assertForbidden();

        $this->actingAs($hr)
            ->patch(route('audits.findings.cap', $finding), ['status' => 'closed', 'corrective_action' => ''])
            ->assertSessionHasErrors('corrective_action');

        $this->actingAs($hr)
            ->patch(route('audits.findings.cap', $compliant), ['status' => 'closed', 'corrective_action' => 'n/a'])
            ->assertSessionHas('error');
        $this->assertNull($compliant->fresh()->status);

        $this->actingAs($hr)->patch(route('audits.findings.cap', $finding), [
            'status' => 'closed',
            'corrective_action' => 'Exit cleared, daily checklist introduced.',
            'responsible_person' => 'Admin Manager',
            'target_date' => '2026-09-20',
        ])->assertSessionHas('success');

        $finding->refresh();
        $this->assertSame('closed', $finding->status);
        $this->assertSame($hr->id, $finding->closed_by);
        $this->assertNotNull($finding->closed_at);

        $this->actingAs($hr)->patch(route('audits.findings.cap', $finding), [
            'status' => 'open',
            'corrective_action' => 'Exit blocked again.',
        ]);
        $this->assertNull($finding->fresh()->closed_at);
    }

    public function test_an_open_finding_past_its_completion_date_is_overdue(): void
    {
        $audit = $this->completedAudit($this->auditor());
        $finding = $audit->findings()->where('rating', 'major')->firstOrFail();

        $finding->update(['target_date' => today()->subDay()]);
        $this->assertTrue($finding->fresh()->is_overdue);

        $this->actingAs($this->userWith(['view audits']))
            ->get(route('audits.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.overdue_caps', 1)
                ->where('audits.0.overdue_count', 1));

        $finding->update(['status' => 'closed']);
        $this->assertFalse($finding->fresh()->is_overdue);
    }

    public function test_a_follow_up_starts_from_the_previous_audit_and_its_open_findings(): void
    {
        $auditor = $this->auditor();
        $source = $this->completedAudit($auditor);

        $this->actingAs($auditor)
            ->get(route('audits.create', ['from' => $source->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Audits/Create')
                ->where('followUpOf.reference_no', $source->reference_no)
                ->where('audit.audit_type', 'follow_up')
                ->where('audit.unit_id', $this->unit->id)
                ->has('audit.findings', 1)
                ->where('audit.findings.0.target_date', '2026-10-01'));
    }

    public function test_the_printable_report_renders_escaped_content(): void
    {
        $auditor = $this->auditor();
        $this->actingAs($auditor)->post(route('audits.store'), $this->payload([
            'findings' => [
                ['category' => 'Training', 'rating' => 'minor', 'finding' => '<script>alert(1)</script> No fire drill record'],
            ],
        ]));
        $audit = Audit::firstOrFail();

        $this->actingAs($this->userWith(['view audits']))
            ->get(route('audits.print', $audit))
            ->assertOk()
            ->assertSee($audit->reference_no)
            ->assertSee('No fire drill record')
            ->assertDontSee('<script>alert(1)</script>', false);
    }
}
