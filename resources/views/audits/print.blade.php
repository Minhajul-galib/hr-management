@php
    use App\Support\AuditTemplate;

    $logo = \App\Models\Setting::get('site_logo_light', \App\Models\Setting::get('site_logo'));
    $companyName = \App\Models\Setting::get('site_name', 'Clifton Group');
    $manpower = $audit->manpower ?? ['workers' => [], 'staff' => []];
    $workersTotal = collect($manpower['workers'] ?? [])->sum(fn ($r) => (int) ($r['count'] ?? 0));
    $staffTotal = collect($manpower['staff'] ?? [])->sum(fn ($r) => (int) ($r['count'] ?? 0));
    $text = fn ($value) => filled($value) ? nl2br(e($value)) : '&nbsp;';
    $date = fn ($value) => $value ? $value->format('d M Y') : '';
    $dateStr = fn ($value) => $value ? \Carbon\Carbon::parse($value)->format('d M Y') : '';
    $mark = fn ($doc, $status) => ($doc['status'] ?? null) === $status ? '&#10003;' : '';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $audit->reference_no }} — Internal Audit Report &amp; CAP</title>
    <style>
        @page {
            size: A4;
            margin: 16mm 12mm 16mm 12mm;
            @bottom-right { content: "Page " counter(page) " of " counter(pages); font: 9px Arial, sans-serif; color: #555; }
            @bottom-left { content: "{{ $audit->reference_no }}"; font: 9px Arial, sans-serif; color: #555; }
        }
        * { box-sizing: border-box; }
        body { font-family: Calibri, Arial, sans-serif; font-size: 11.5px; color: #111; margin: 0; background: #e5e7eb; }
        .sheet { background: #fff; max-width: 210mm; margin: 16px auto; padding: 14mm 12mm; box-shadow: 0 1px 4px rgba(0,0,0,.15); }
        .toolbar { max-width: 210mm; margin: 16px auto 0; display: flex; gap: 8px; justify-content: flex-end; }
        .toolbar a, .toolbar button { font: 600 12px Arial, sans-serif; padding: 8px 14px; border-radius: 6px; border: 1px solid #d1d5db; background: #fff; color: #111; cursor: pointer; text-decoration: none; }
        .toolbar button { background: #4f46e5; border-color: #4f46e5; color: #fff; }
        .doc-header { display: flex; align-items: center; justify-content: space-between; border-bottom: 2px solid #1f3864; padding-bottom: 6px; margin-bottom: 12px; }
        .doc-header h1 { font-size: 17px; margin: 0; color: #1f3864; }
        .doc-header .meta { text-align: right; font-size: 11px; color: #333; }
        .doc-header img { height: 34px; margin-right: 10px; }
        .brand { display: flex; align-items: center; }
        h2 { font-size: 13px; margin: 16px 0 6px; color: #1f3864; }
        table { width: 100%; border-collapse: collapse; page-break-inside: auto; }
        tr { page-break-inside: avoid; }
        th, td { border: 1px solid #555; padding: 4px 6px; vertical-align: top; text-align: left; }
        th { background: #d9e2f3; font-weight: 700; }
        .band { background: #1f3864; color: #fff; text-align: center; font-weight: 700; }
        .category { background: #fbe4d5; font-weight: 700; }
        .label { background: #f2f2f2; font-weight: 600; width: 22%; }
        .center { text-align: center; }
        .num { width: 36px; text-align: center; }
        .muted { color: #555; font-size: 10px; }
        .two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .rating { font-weight: 700; white-space: nowrap; }
        .rating-zero_tolerance { color: #b91c1c; }
        .rating-critical { color: #c2410c; }
        .rating-major { color: #b45309; }
        .rating-minor { color: #1d4ed8; }
        .rating-observation { color: #4b5563; }
        .rating-compliant { color: #15803d; }
        .swatch { display: inline-block; width: 10px; height: 10px; border: 1px solid #333; margin-right: 4px; vertical-align: middle; }
        .signatures { display: grid; grid-template-columns: repeat(3, 1fr); gap: 24px; margin-top: 40px; }
        .signatures div { border-top: 1px solid #111; padding-top: 4px; text-align: center; font-size: 11px; }
        .draft-flag { color: #b91c1c; font-weight: 700; border: 1px solid #b91c1c; padding: 1px 6px; border-radius: 3px; font-size: 10px; }
        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .sheet { box-shadow: none; margin: 0; padding: 0; max-width: none; }
        }
    </style>
</head>
<body>
<div class="toolbar">
    <a href="{{ route('audits.show', $audit) }}">&larr; Back to audit</a>
    <button type="button" onclick="window.print()">Print / Save as PDF</button>
</div>

<div class="sheet">
    <div class="doc-header">
        <div class="brand">
            @if ($logo)
                <img src="{{ asset('storage/'.$logo) }}" alt="">
            @endif
            <h1>Internal Audit Report &amp; CAP</h1>
        </div>
        <div class="meta">
            @unless ($logo)
                <strong>{{ $companyName }}</strong><br>
            @endunless
            <strong>{{ $audit->reference_no }}</strong>
            @unless ($audit->isCompleted())
                &nbsp;<span class="draft-flag">DRAFT</span>
            @endunless
        </div>
    </div>

    <table>
        <tr>
            <th colspan="2" class="band">FACILITY INFORMATION</th>
            <th colspan="2" class="band">ASSESSMENT INFORMATION</th>
        </tr>
        <tr>
            <td class="label">Facility Name</td><td>{{ $audit->facility_name }}</td>
            <td class="label">Assessment date</td><td>{{ $date($audit->assessment_date) }}</td>
        </tr>
        <tr>
            <td class="label">Address</td><td>{!! $text($audit->facility_address) !!}</td>
            <td class="label">Assessment standard</td><td>{{ $audit->assessment_standard }}</td>
        </tr>
        <tr>
            <td class="label">Number of Employees</td><td>{{ $audit->number_of_employees }}</td>
            <td class="label">Assessment Partner</td><td>{{ $audit->assessment_partner }}</td>
        </tr>
        @php $reps = $audit->factory_representatives ?: [[]]; @endphp
        <tr>
            <td colspan="2" rowspan="{{ 4 + count($reps) }}"></td>
            <td class="label">Auditor(s) Name</td>
            <td>
                @foreach ($audit->auditors ?? [] as $i => $name)
                    {{ $i + 1 }}. {{ $name }}<br>
                @endforeach
            </td>
        </tr>
        <tr><td class="label">Audit Type</td><td>{{ AuditTemplate::TYPES[$audit->audit_type] ?? $audit->audit_type }}</td></tr>
        <tr><th colspan="2" class="center">Factory Representative(s)</th></tr>
        @foreach ($reps as $i => $rep)
            <tr>
                <td class="label">Name{{ count($reps) > 1 ? ' '.($i + 1) : '' }}</td>
                <td>
                    {{ $rep['name'] ?? '' }}
                    @if (! empty($rep['title']) || ! empty($rep['department']))
                        — {{ collect([$rep['title'] ?? null, $rep['department'] ?? null])->filter()->join(', ') }}
                    @endif
                </td>
            </tr>
        @endforeach
        <tr><td class="label">Signature</td><td style="height: 34px;"></td></tr>
    </table>

    <h2>1. Short Description</h2>
    <table>
        <tr>
            <td class="label">This factory was established in</td><td>{{ $audit->established_year }}</td>
            <td class="label">Recent Buyer</td><td>{{ $audit->recent_buyer }}</td>
        </tr>
        <tr>
            <td class="label">Square feet</td><td>{{ $audit->total_area }}</td>
            <td class="label">Monthly capacity</td><td>{{ $audit->monthly_capacity }}</td>
        </tr>
        <tr>
            <td class="label">Warehouse/store area</td><td>{{ $audit->warehouse_area }}</td>
            <td class="label">Total line</td><td>{{ $audit->total_lines }}</td>
        </tr>
        <tr>
            <td class="label">Production area</td><td>{{ $audit->production_area }}</td>
            <td class="label">Total employee</td><td>{{ $audit->number_of_employees }}</td>
        </tr>
        <tr>
            <td class="label">Office &amp; others</td><td>{{ $audit->office_area }}</td>
            <td class="label">Total machine</td><td>{{ $audit->total_machines }}</td>
        </tr>
    </table>

    <h2>Manpower details</h2>
    <div class="two-col">
        @foreach (['workers' => ['Workers', $workersTotal, 'Total number of workers'], 'staff' => ['Staff', $staffTotal, 'Total number of staffs']] as $group => [$title, $total, $totalLabel])
            <table>
                <tr><th class="num">Sl.</th><th>Designation ({{ $title }})</th><th class="num" style="width: 60px;">Number</th></tr>
                @foreach ($manpower[$group] ?? [] as $i => $row)
                    <tr><td class="num">{{ $i + 1 }}</td><td>{{ $row['designation'] }}</td><td class="center">{{ $row['count'] }}</td></tr>
                @endforeach
                <tr><th></th><th>{{ $totalLabel }}</th><th class="center">{{ $total }}</th></tr>
            </table>
        @endforeach
    </div>
    <p class="muted">Total manpower (workers + staff): <strong>{{ $workersTotal + $staffTotal }}</strong></p>

    <h2>2. Floor Layout – ({{ $audit->building_storeys ?: '----' }} Storied Building)</h2>
    <p class="muted" style="margin: 0 0 4px;">Main Building:</p>
    <table>
        @forelse ($audit->floor_layout ?? [] as $row)
            <tr><td class="label">{{ $row['floor'] }}</td><td>{!! $text($row['usage'] ?? null) !!}</td></tr>
        @empty
            <tr><td class="center muted">No floor layout recorded.</td></tr>
        @endforelse
    </table>

    <h2>3. Legal Documents</h2>
    <table>
        <tr><th class="num">Sl.</th><th>Certificates/License</th><th class="num">Yes</th><th class="num">No</th><th class="num">N/A</th><th style="width: 80px;">Valid Up To</th><th>Remarks</th></tr>
        @foreach ($audit->legal_documents ?? [] as $i => $doc)
            <tr>
                <td class="num">{{ $i + 1 }}</td>
                <td>{{ $doc['name'] }}</td>
                <td class="center">{!! $mark($doc, 'yes') !!}</td>
                <td class="center">{!! $mark($doc, 'no') !!}</td>
                <td class="center">{!! $mark($doc, 'na') !!}</td>
                <td>{{ $dateStr($doc['valid_until'] ?? null) }}</td>
                <td>{{ $doc['remarks'] ?? '' }}</td>
            </tr>
        @endforeach
    </table>

    <h2>4. List of Fire Fighting Equipment</h2>
    <table>
        <tr><th class="num">Sl.</th><th>Equipment Name</th><th style="width: 80px;" class="center">Quantity</th><th>Remarks</th></tr>
        @foreach ($audit->fire_equipment ?? [] as $i => $item)
            <tr>
                <td class="num">{{ $i + 1 }}</td>
                <td>{{ $item['name'] }}</td>
                <td class="center">{{ $item['quantity'] ?? '' }}</td>
                <td>{{ $item['remarks'] ?? '' }}</td>
            </tr>
        @endforeach
    </table>

    <h2>5. Audit result</h2>
    <table>
        <tr>
            <th class="num">Sl.</th>
            <th style="width: 88px;">Rating<br><span class="muted">[ZT, Critical, Major, Minor, Observation]</span></th>
            <th>Audit Findings / Non-Compliances</th>
            <th>Factory corrective action plan</th>
            <th style="width: 110px;">Responsible person's name and title</th>
            <th style="width: 80px;">Completion date</th>
        </tr>
        @php $sl = 0; @endphp
        @forelse ($findingsByCategory as $category => $findings)
            <tr><td colspan="6" class="category">{{ $category }}</td></tr>
            @foreach ($findings as $finding)
                <tr>
                    <td class="num">{{ ++$sl }}</td>
                    <td class="rating rating-{{ $finding->rating }}">{{ AuditTemplate::RATINGS[$finding->rating] ?? $finding->rating }}</td>
                    <td>{!! $text($finding->finding) !!}</td>
                    <td>{!! $finding->requiresCap() ? $text($finding->corrective_action) : '<span class="muted">Not required</span>' !!}</td>
                    <td>{{ $finding->responsible_person }}</td>
                    <td>
                        {{ $date($finding->target_date) }}
                        @if ($finding->status === 'closed')
                            <br><span class="muted">Closed {{ $date($finding->closed_at) }}</span>
                        @elseif ($finding->is_overdue)
                            <br><span class="muted" style="color:#b91c1c;">Overdue</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        @empty
            <tr><td colspan="6" class="center muted">No findings recorded.</td></tr>
        @endforelse
    </table>

    <h2>Result and Comments</h2>
    <table>
        @foreach (AuditTemplate::RESULTS as $key => $result)
            <tr>
                <td class="label" style="width: 30%;">
                    {!! $audit->overall_result === $key ? '&#9745;' : '&#9744;' !!} {{ $result['label'] }}
                </td>
                <td style="width: 20%;">
                    @if ($result['color'])
                        <span class="swatch" style="background: {{ strtolower($result['color']) }};"></span>{{ $result['color'] }} rating
                    @endif
                </td>
                @if ($loop->first)
                    <td rowspan="{{ count(AuditTemplate::RESULTS) }}">{!! $text($audit->result_comments) !!}</td>
                @endif
            </tr>
        @endforeach
    </table>

    <div class="signatures">
        @foreach (array_slice($audit->auditors ?? [], 0, 2) as $name)
            <div>{{ $name }}<br><span class="muted">Auditor</span></div>
        @endforeach
        @forelse (array_slice($audit->factory_representatives ?? [], 0, 2) as $rep)
            <div>{{ $rep['name'] ?? '' }}<br><span class="muted">Factory Representative{{ !empty($rep['title']) ? ' — '.$rep['title'] : '' }}</span></div>
        @empty
            <div>&nbsp;<br><span class="muted">Factory Representative</span></div>
        @endforelse
    </div>
</div>
</body>
</html>
