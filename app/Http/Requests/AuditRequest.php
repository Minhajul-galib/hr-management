<?php

namespace App\Http\Requests;

use App\Support\AuditTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AuditRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage audits');
    }

    protected function prepareForValidation(): void
    {
        $auditors = array_values(array_filter(
            array_map(fn ($name) => is_string($name) ? trim($name) : $name, (array) $this->input('auditors', [])),
            fn ($name) => $name !== '' && $name !== null
        ));

        $representatives = array_values(array_filter(
            (array) $this->input('factory_representatives', []),
            fn ($row) => is_array($row) && (filled($row['name'] ?? null) || filled($row['title'] ?? null) || filled($row['department'] ?? null))
        ));

        $this->merge(['auditors' => $auditors, 'factory_representatives' => $representatives]);
    }

    public function rules(): array
    {
        $completing = $this->input('action') === 'complete';

        return [
            'action' => ['required', Rule::in(['draft', 'complete'])],

            'unit_id' => ['required', 'integer', Rule::exists('units', 'id')->whereNull('deleted_at')],
            'facility_address' => ['nullable', 'string', 'max:500'],
            'number_of_employees' => ['nullable', 'integer', 'min:0'],

            'assessment_date' => ['required', 'date'],
            'assessment_standard' => ['required', Rule::in(AuditTemplate::STANDARDS)],
            'assessment_partner' => ['nullable', 'string', 'max:255'],
            'audit_type' => ['required', Rule::in(array_keys(AuditTemplate::TYPES))],
            'auditors' => ['array', 'min:1', 'max:10'],
            'auditors.*' => ['string', 'max:255'],
            'factory_representatives' => ['nullable', 'array', 'max:20'],
            'factory_representatives.*' => ['array:name,title,department'],
            'factory_representatives.*.name' => ['nullable', 'string', 'max:255'],
            'factory_representatives.*.title' => ['nullable', 'string', 'max:255'],
            'factory_representatives.*.department' => ['nullable', 'string', 'max:255'],

            'established_year' => ['nullable', 'string', 'max:50'],
            'total_area' => ['nullable', 'string', 'max:100'],
            'warehouse_area' => ['nullable', 'string', 'max:100'],
            'production_area' => ['nullable', 'string', 'max:100'],
            'office_area' => ['nullable', 'string', 'max:100'],
            'recent_buyer' => ['nullable', 'string', 'max:255'],
            'monthly_capacity' => ['nullable', 'string', 'max:100'],
            'total_lines' => ['nullable', 'integer', 'min:0'],
            'total_machines' => ['nullable', 'integer', 'min:0'],

            'manpower' => ['nullable', 'array:workers,staff'],
            'manpower.workers' => ['array', 'max:50'],
            'manpower.staff' => ['array', 'max:50'],
            'manpower.*.*' => ['array:designation,count'],
            'manpower.*.*.designation' => ['required', 'string', 'max:255'],
            'manpower.*.*.count' => ['nullable', 'integer', 'min:0'],

            'building_storeys' => ['nullable', 'string', 'max:50'],
            'floor_layout' => ['nullable', 'array', 'max:50'],
            'floor_layout.*' => ['array:floor,usage'],
            'floor_layout.*.floor' => ['required', 'string', 'max:100'],
            'floor_layout.*.usage' => ['nullable', 'string', 'max:1000'],

            'legal_documents' => ['nullable', 'array', 'max:100'],
            'legal_documents.*' => ['array:name,status,valid_until,remarks'],
            'legal_documents.*.name' => ['required', 'string', 'max:255'],
            'legal_documents.*.status' => ['nullable', Rule::in(['yes', 'no', 'na'])],
            'legal_documents.*.valid_until' => ['nullable', 'date'],
            'legal_documents.*.remarks' => ['nullable', 'string', 'max:500'],

            'fire_equipment' => ['nullable', 'array', 'max:100'],
            'fire_equipment.*' => ['array:name,quantity,remarks'],
            'fire_equipment.*.name' => ['required', 'string', 'max:255'],
            'fire_equipment.*.quantity' => ['nullable', 'integer', 'min:0'],
            'fire_equipment.*.remarks' => ['nullable', 'string', 'max:500'],

            'findings' => ['nullable', 'array', 'max:300'],
            'findings.*.id' => ['nullable', 'integer'],
            'findings.*.category' => ['required', Rule::in(AuditTemplate::CATEGORIES)],
            'findings.*.rating' => ['required', Rule::in(array_keys(AuditTemplate::RATINGS))],
            'findings.*.finding' => ['required', 'string', 'max:5000'],
            'findings.*.corrective_action' => ['nullable', 'string', 'max:5000'],
            'findings.*.responsible_person' => ['nullable', 'string', 'max:255'],
            'findings.*.target_date' => ['nullable', 'date'],
            'findings.*.status' => ['nullable', Rule::in(['open', 'closed'])],

            'overall_result' => [$completing ? 'required' : 'nullable', Rule::in(array_keys(AuditTemplate::RESULTS))],
            'result_comments' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'unit_id' => 'facility',
            'auditors' => 'auditor names',
            'findings.*.finding' => 'finding',
            'findings.*.category' => 'category',
            'findings.*.rating' => 'rating',
            'overall_result' => 'overall result',
        ];
    }

    public function messages(): array
    {
        return [
            'auditors.min' => 'Enter at least one auditor name.',
            'overall_result.required' => 'Select the overall result before completing the audit.',
        ];
    }
}
