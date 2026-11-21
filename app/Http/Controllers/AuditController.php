<?php

namespace App\Http\Controllers;

use App\Http\Requests\AuditRequest;
use App\Models\Audit;
use App\Models\AuditFinding;
use App\Models\Unit;
use App\Models\User;
use App\Support\AuditTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AuditController extends Controller
{
    public function index()
    {
        $audits = Audit::query()
            ->with(['unit:id,name,short_form', 'creator:id,name'])
            ->withCount([
                'findings as issues_count' => fn ($q) => $q->where('rating', '!=', 'compliant'),
                'findings as open_count' => fn ($q) => $q->open(),
                'findings as overdue_count' => fn ($q) => $q->open()->whereDate('target_date', '<', today()),
                'findings as zero_tolerance_count' => fn ($q) => $q->where('rating', 'zero_tolerance'),
                'findings as critical_count' => fn ($q) => $q->where('rating', 'critical'),
                'findings as major_count' => fn ($q) => $q->where('rating', 'major'),
                'findings as minor_count' => fn ($q) => $q->where('rating', 'minor'),
                'findings as observation_count' => fn ($q) => $q->where('rating', 'observation'),
            ])
            ->orderByDesc('assessment_date')
            ->orderByDesc('id')
            ->get();

        return Inertia::render('Audits/Index', [
            'audits' => $audits,
            'stats' => [
                'total' => $audits->count(),
                'drafts' => $audits->where('status', Audit::STATUS_DRAFT)->count(),
                'open_caps' => $audits->sum('open_count'),
                'overdue_caps' => $audits->sum('overdue_count'),
            ],
            'units' => Unit::orderBy('name')->get(['id', 'name', 'short_form']),
            'template' => AuditTemplate::forFrontend(),
        ]);
    }

    public function create(Request $request)
    {
        $source = $request->filled('from')
            ? Audit::with('findings')->find($request->integer('from'))
            : null;

        return Inertia::render('Audits/Create', [
            'audit' => $source ? $this->followUpOf($source) : $this->blankAudit($request->user()),
            'followUpOf' => $source?->only(['id', 'reference_no', 'facility_name']),
            'units' => $this->selectableUnits($source?->unit_id),
            'users' => $this->selectableAuditors(),
            'template' => AuditTemplate::forFrontend(),
        ]);
    }

    public function store(AuditRequest $request)
    {
        $audit = DB::transaction(function () use ($request) {
            $audit = Audit::create([
                ...$this->auditAttributes($request),
                'created_by' => $request->user()->id,
            ]);
            $this->syncFindings($audit, $request->validated('findings') ?? [], $request->user());
            $this->applyAction($audit, $request);

            return $audit;
        });

        return $this->redirectAfterSave($audit);
    }

    public function show(Audit $audit)
    {
        $audit->load([
            'unit:id,name,short_form,location',
            'creator:id,name',
            'completer:id,name',
            'findings' => fn ($q) => $q->orderBy('sort_order')->orderBy('id')->with('closer:id,name'),
        ]);

        return Inertia::render('Audits/Show', [
            'audit' => $audit,
            'template' => AuditTemplate::forFrontend(),
        ]);
    }

    public function edit(Audit $audit)
    {
        if ($audit->isCompleted()) {
            return redirect()->route('audits.show', $audit)
                ->with('error', 'This audit is completed and locked. Reopen it to make changes.');
        }

        $audit->load(['findings' => fn ($q) => $q->orderBy('sort_order')->orderBy('id')]);

        return Inertia::render('Audits/Edit', [
            'audit' => $audit,
            'units' => $this->selectableUnits($audit->unit_id),
            'users' => $this->selectableAuditors(),
            'template' => AuditTemplate::forFrontend(),
        ]);
    }

    public function update(AuditRequest $request, Audit $audit)
    {
        if ($audit->isCompleted()) {
            return redirect()->route('audits.show', $audit)
                ->with('error', 'This audit is completed and locked. Reopen it to make changes.');
        }

        DB::transaction(function () use ($request, $audit) {
            $audit->update($this->auditAttributes($request));
            $this->syncFindings($audit, $request->validated('findings') ?? [], $request->user());
            $this->applyAction($audit, $request);
        });

        return $this->redirectAfterSave($audit);
    }

    public function destroy(Audit $audit)
    {
        if ($audit->isCompleted()) {
            return back()->with('error', 'Completed audits cannot be deleted. Reopen it first.');
        }

        $audit->delete();

        return redirect()->route('audits.index')->with('success', 'Draft audit deleted.');
    }

    public function reopen(Request $request, Audit $audit)
    {
        if (! $audit->isCompleted()) {
            return back()->with('error', 'Only completed audits can be reopened.');
        }

        $audit->forceFill([
            'status' => Audit::STATUS_DRAFT,
            'completed_by' => null,
            'completed_at' => null,
        ])->save();

        activity()->performedOn($audit)->causedBy($request->user())
            ->log("Reopened internal audit {$audit->reference_no}");

        return redirect()->route('audits.edit', $audit)->with('success', 'Audit reopened for editing.');
    }

    public function updateCap(Request $request, AuditFinding $finding)
    {
        if (! $finding->audit->isCompleted()) {
            return back()->with('error', 'The corrective action plan can be updated once the audit is completed.');
        }

        if (! $finding->requiresCap()) {
            return back()->with('error', 'Compliant findings do not need a corrective action plan.');
        }

        $data = $request->validate([
            'corrective_action' => [$request->input('status') === AuditFinding::STATUS_CLOSED ? 'required' : 'nullable', 'string', 'max:5000'],
            'responsible_person' => ['nullable', 'string', 'max:255'],
            'target_date' => ['nullable', 'date'],
            'status' => ['required', Rule::in([AuditFinding::STATUS_OPEN, AuditFinding::STATUS_CLOSED])],
        ], [
            'corrective_action.required' => 'Describe the corrective action before closing this finding.',
        ]);

        $this->fillCap($finding, [...$data, 'rating' => $finding->rating], $request->user());
        $finding->save();

        return back()->with('success', 'Corrective action plan updated.');
    }

    public function print(Audit $audit)
    {
        $audit->load([
            'unit:id,name,location',
            'findings' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'),
        ]);

        return view('audits.print', [
            'audit' => $audit,
            'findingsByCategory' => $audit->findings->groupBy('category'),
        ]);
    }

    private function auditAttributes(AuditRequest $request): array
    {
        $data = $request->safe()->except(['action', 'findings']);
        $data['facility_name'] = Unit::findOrFail($data['unit_id'])->name;

        return $data;
    }

    private function syncFindings(Audit $audit, array $rows, User $user): void
    {
        $existing = $audit->findings()->get()->keyBy('id');
        $kept = [];

        foreach (array_values($rows) as $index => $row) {
            // An id that doesn't belong to this audit is treated as a new finding, never as someone else's row.
            $finding = $existing->get($row['id'] ?? null) ?? $audit->findings()->make();

            $finding->fill([
                'category' => $row['category'],
                'rating' => $row['rating'],
                'finding' => $row['finding'],
                'sort_order' => $index,
            ]);
            $this->fillCap($finding, $row, $user);
            $finding->save();

            $kept[] = $finding->id;
        }

        $audit->findings()->whereNotIn('id', $kept)->delete();
    }

    private function fillCap(AuditFinding $finding, array $data, User $user): void
    {
        if ($data['rating'] === 'compliant') {
            $finding->fill([
                'corrective_action' => null,
                'responsible_person' => null,
                'target_date' => null,
                'status' => null,
                'closed_by' => null,
                'closed_at' => null,
            ]);

            return;
        }

        $status = $data['status'] ?? AuditFinding::STATUS_OPEN;

        $finding->fill([
            'corrective_action' => $data['corrective_action'] ?? null,
            'responsible_person' => $data['responsible_person'] ?? null,
            'target_date' => $data['target_date'] ?? null,
            'status' => $status,
        ]);

        if ($status === AuditFinding::STATUS_OPEN) {
            $finding->fill(['closed_by' => null, 'closed_at' => null]);
        } elseif ($finding->closed_at === null) {
            $finding->fill(['closed_by' => $user->id, 'closed_at' => now()]);
        }
    }

    private function applyAction(Audit $audit, AuditRequest $request): void
    {
        if ($request->input('action') !== 'complete') {
            return;
        }

        $audit->forceFill([
            'status' => Audit::STATUS_COMPLETED,
            'completed_by' => $request->user()->id,
            'completed_at' => now(),
        ])->save();

        activity()->performedOn($audit)->causedBy($request->user())
            ->log("Completed internal audit {$audit->reference_no}");
    }

    private function redirectAfterSave(Audit $audit)
    {
        return $audit->isCompleted()
            ? redirect()->route('audits.show', $audit)->with('success', "Audit {$audit->reference_no} completed.")
            : redirect()->route('audits.edit', $audit)->with('success', 'Draft saved.');
    }

    private function selectableUnits(?int $includeUnitId = null)
    {
        return Unit::query()
            ->where(fn ($q) => $q->where('is_active', true)->when($includeUnitId, fn ($q) => $q->orWhere('id', $includeUnitId)))
            ->orderBy('name')
            ->get(['id', 'name', 'short_form', 'location']);
    }

    private function selectableAuditors()
    {
        return User::orderBy('name')->get(['id', 'name']);
    }

    private function blankAudit(User $user): array
    {
        return [
            'unit_id' => null,
            'facility_address' => null,
            'number_of_employees' => null,
            'assessment_date' => today()->toDateString(),
            'assessment_standard' => AuditTemplate::STANDARDS[0],
            'assessment_partner' => 'Internal Auditor (Self)',
            'audit_type' => 'initial',
            'auditors' => [$user->name],
            'factory_representatives' => [],
            'established_year' => null,
            'total_area' => null,
            'warehouse_area' => null,
            'production_area' => null,
            'office_area' => null,
            'recent_buyer' => null,
            'monthly_capacity' => null,
            'total_lines' => null,
            'total_machines' => null,
            'manpower' => AuditTemplate::manpower(),
            'building_storeys' => null,
            'floor_layout' => AuditTemplate::floorLayout(),
            'legal_documents' => AuditTemplate::legalDocuments(),
            'fire_equipment' => AuditTemplate::fireEquipment(),
            'findings' => [],
            'overall_result' => null,
            'result_comments' => null,
        ];
    }

    /** A follow-up re-checks the same facility, so it starts from the previous report and its still-open findings. */
    private function followUpOf(Audit $source): array
    {
        return [
            ...$source->only([
                'unit_id', 'facility_address', 'number_of_employees', 'assessment_standard', 'assessment_partner',
                'auditors', 'factory_representatives', 'established_year', 'total_area',
                'warehouse_area', 'production_area', 'office_area', 'recent_buyer', 'monthly_capacity',
                'total_lines', 'total_machines', 'manpower', 'building_storeys', 'floor_layout',
                'legal_documents', 'fire_equipment',
            ]),
            'assessment_date' => today()->toDateString(),
            'audit_type' => 'follow_up',
            'findings' => $source->findings
                ->where('status', AuditFinding::STATUS_OPEN)
                ->sortBy('sort_order')
                ->map(fn (AuditFinding $f) => [
                    ...$f->only(['category', 'rating', 'finding', 'corrective_action', 'responsible_person', 'status']),
                    'target_date' => $f->target_date?->toDateString(),
                ])
                ->values(),
            'overall_result' => null,
            'result_comments' => null,
        ];
    }
}
