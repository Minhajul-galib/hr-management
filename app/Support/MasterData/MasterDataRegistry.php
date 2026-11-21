<?php

namespace App\Support\MasterData;

use App\Models\AcrTemplate;
use App\Models\Department;
use App\Models\Designation;
use App\Models\DocumentType;
use App\Models\Grade;
use App\Models\LeaveType;
use App\Models\OffenceType;
use App\Models\SalaryType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * Describes every HR master-data screen: its model, form fields, table
 * columns, validation and deletion guards. One generic controller and one
 * generic Vue page render all of them from these definitions, so adding a
 * master means adding an entry here (plus a table and a model).
 *
 * Field types: text, number, textarea, select (static `options`, or a `lookup` of another
 * master), boolean, multiselect, repeater.
 * Column types: text, number, badge, boolean, count, tags, range, rate.
 *
 * A multiselect's `lookup` may add `extra` (option data for the page), and the
 * field may set `breakdown` (show what the chosen options add up to) and
 * `preselect_active` (tick every active option on a new record). A master may
 * set `computed`: a closure returning extra values to send with each row.
 *
 * Uniqueness: every master's `code` (numbers and small letters only, see Code)
 * (or its `import.match` fields) identifies a record, has a unique index in the
 * database and is how other records will refer to it later (an employee's department code, a grade's grade type code).
 * `unique` lists any other column sets that must not repeat, compared ignoring
 * case; the form rules enforce them against saved data and the import also
 * checks them between the rows of one file. Tests check each has a unique index.
 *
 * Optional, for masters that grow large:
 *  - `paginate`: search, filter and page on the server instead of sending every row.
 *    ['per_page' => default page size, 'search' => columns the search box looks in]
 *  - `import`: Excel import/export of the scalar fields (see MasterDataImporter).
 *    ['match' => field(s) that identify a row, 'sheet' => sheet name,
 *     'aliases' => [field => other accepted headings],
 *     'examples' => two sample rows, written into the template (and skipped on import while unchanged),
 *     'warn_duplicates' => fields that usually differ per record; a repeat is flagged but allowed]
 *    A select with a `lookup` is imported by the other master's code; a multiselect by a
 *    comma-separated list of codes (e.g. a grade's salary types: "120, 652").
 */
final class MasterDataRegistry
{
    public const ACCRUAL = [
        ['value' => 'yearly', 'label' => 'Yearly (credited upfront)'],
        ['value' => 'monthly', 'label' => 'Monthly (1/12 each month)'],
        ['value' => 'none', 'label' => 'None (no periodic credit)'],
    ];

    public const SEVERITY = [
        ['value' => 'minor', 'label' => 'Minor', 'color' => 'yellow'],
        ['value' => 'major', 'label' => 'Major', 'color' => 'orange'],
        ['value' => 'gross', 'label' => 'Gross misconduct', 'color' => 'red'],
    ];

    public const MODULES = [
        ['value' => 'employee_profile', 'label' => 'Employee Profile', 'color' => 'blue'],
        ['value' => 'recruitment', 'label' => 'Recruitment', 'color' => 'purple'],
        ['value' => 'leave', 'label' => 'Leave', 'color' => 'green'],
        ['value' => 'disciplinary', 'label' => 'Disciplinary', 'color' => 'red'],
        ['value' => 'acr', 'label' => 'ACR / Performance', 'color' => 'yellow'],
        ['value' => 'payroll', 'label' => 'Payroll', 'color' => 'orange'],
        ['value' => 'separation', 'label' => 'Separation', 'color' => 'gray'],
    ];

    /** Validation messages shared by the form and the Excel import. */
    public const MESSAGES = [
        'code.regex' => Code::MESSAGE,
    ];

    public const CALCULATIONS = [
        ['value' => SalaryType::PERCENT_OF_BASIC, 'label' => '% of basic salary', 'color' => 'blue'],
        ['value' => SalaryType::FIXED, 'label' => 'Fixed amount', 'color' => 'gray'],
    ];

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $definitions = null;

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        return self::$definitions ??= self::build();
    }

    /** @return array<string, mixed>|null */
    public static function find(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /** @return list<string> */
    public static function permissionNames(): array
    {
        $names = [];
        foreach (self::all() as $definition) {
            $names[] = 'view '.$definition['permission'];
            $names[] = 'manage '.$definition['permission'];
        }

        return $names;
    }

    /** @return list<string> */
    public static function viewPermissionNames(): array
    {
        return array_values(array_filter(
            self::permissionNames(),
            fn (string $name) => str_starts_with($name, 'view '),
        ));
    }

    /** @return array<string, mixed> */
    private static function build(): array
    {
        return [
            'departments' => [
                'label' => 'Departments',
                'singular' => 'Department',
                'description' => 'Every department of the company with its short name and code. Employees will be assigned to these.',
                'permission' => 'departments',
                'model' => Department::class,
                'title_key' => 'name',
                'order' => [['code', 'asc']],
                'paginate' => ['per_page' => 25, 'search' => ['code', 'name', 'short_name']],
                'import' => [
                    'match' => 'code',
                    'sheet' => 'Departments',
                    'aliases' => [
                        'code' => ['department_code', 'dept_code'],
                        'name' => ['name', 'department', 'dept_name'],
                        'short_name' => ['short_form', 'short', 'abbreviation'],
                        'is_active' => ['status'],
                    ],
                    'examples' => [
                        ['code' => '101', 'name' => 'Human Resources', 'short_name' => 'HR', 'description' => 'Recruitment, payroll and employee relations', 'is_active' => true],
                        ['code' => '102', 'name' => 'Merchandising', 'short_name' => 'MER', 'description' => '', 'is_active' => true],
                    ],
                    'warn_duplicates' => ['short_name'],
                ],
                'unique' => [['name']],
                'columns' => [
                    ['key' => 'code', 'label' => 'Code', 'type' => 'text', 'strong' => true],
                    ['key' => 'name', 'label' => 'Department Name', 'type' => 'text'],
                    ['key' => 'short_name', 'label' => 'Short Name', 'type' => 'text'],
                    ['key' => 'description', 'label' => 'Description', 'type' => 'text', 'muted' => true],
                    self::statusColumn(),
                ],
                'fields' => [
                    self::codeField(),
                    ['key' => 'name', 'label' => 'Department Name', 'type' => 'text', 'required' => true, 'maxlength' => 255, 'placeholder' => 'e.g. Human Resources'],
                    ['key' => 'short_name', 'label' => 'Short Name', 'type' => 'text', 'required' => true, 'uppercase' => true, 'maxlength' => 50, 'placeholder' => 'e.g. HR'],
                    ['key' => 'description', 'label' => 'Description', 'type' => 'textarea', 'full' => true],
                    self::activeField(),
                ],
                'rules' => fn (?int $id, array $input) => [
                    'code' => self::codeRules('departments', $id),
                    'name' => ['required', 'string', 'max:255', self::uniqueName(Department::class, $id, 'Department')],
                    'short_name' => ['required', 'string', 'max:50'],
                    'description' => ['nullable', 'string', 'max:2000'],
                    'is_active' => ['boolean'],
                ],
                'in_use' => function (Model $department): ?string {
                    $count = $department->users()->count();

                    return $count ? "{$count} user(s) are assigned to it" : null;
                },
            ],

            'designations' => [
                'label' => 'Designations',
                'singular' => 'Designation',
                'description' => 'Job titles in order of seniority. A lower serial is more senior.',
                'permission' => 'designations',
                'model' => Designation::class,
                'title_key' => 'title',
                'order' => [['serial', 'asc'], ['code', 'asc']],
                'import' => [
                    'match' => 'code',
                    'sheet' => 'Designations',
                    'aliases' => [
                        'code' => ['designation_code'],
                        'title' => ['designation', 'name', 'job_title'],
                        'serial' => ['serial_no', 'sl', 'order', 'seniority'],
                        'is_active' => ['status'],
                    ],
                    'examples' => [
                        ['code' => '201', 'title' => 'Managing Director', 'serial' => 1, 'is_active' => true],
                        ['code' => '202', 'title' => 'Senior Executive', 'serial' => 12, 'is_active' => true],
                    ],
                    'warn_duplicates' => ['title'],
                ],
                'columns' => [
                    ['key' => 'serial', 'label' => 'Serial', 'type' => 'number'],
                    ['key' => 'code', 'label' => 'Code', 'type' => 'text', 'strong' => true],
                    ['key' => 'title', 'label' => 'Title', 'type' => 'text'],
                    self::statusColumn(),
                ],
                'fields' => [
                    self::codeField(),
                    ['key' => 'title', 'label' => 'Title', 'type' => 'text', 'required' => true],
                    ['key' => 'serial', 'label' => 'Serial', 'type' => 'number', 'required' => true, 'default' => 0, 'min' => 0, 'step' => 1, 'help' => 'Seniority order. Lower is more senior.'],
                    self::activeField(),
                ],
                'rules' => fn (?int $id, array $input) => [
                    'code' => self::codeRules('designations', $id),
                    'title' => ['required', 'string', 'max:255'],
                    'serial' => ['required', 'integer', 'min:0', 'max:65535'],
                    'is_active' => ['boolean'],
                ],
                'in_use' => function (Model $designation): ?string {
                    $count = $designation->acrTemplates()->count();

                    return $count ? "it is used by {$count} ACR template(s)" : null;
                },
            ],

            'salary-types' => [
                'label' => 'Salary Types',
                'singular' => 'Salary Type',
                'description' => 'The heads paid on top of basic salary, such as house rent, medical and conveyance. Set the percentage here once; every grade that uses it is calculated from it.',
                'permission' => 'salary types',
                'model' => SalaryType::class,
                'title_key' => 'name',
                'order' => [['code', 'asc']],
                'import' => [
                    'match' => 'code',
                    'sheet' => 'Salary Types',
                    'aliases' => [
                        'code' => ['salary_type_code', 'head_code'],
                        'name' => ['salary_type', 'head', 'component', 'allowance'],
                        'calculation' => ['type', 'calculation_type'],
                        'value' => ['percentage', 'percent', 'rate', 'amount'],
                        'is_active' => ['status'],
                    ],
                    'examples' => [
                        ['code' => '120', 'name' => 'House Rent', 'calculation' => SalaryType::PERCENT_OF_BASIC, 'value' => 50, 'description' => 'Paid monthly with the salary', 'is_active' => true],
                        ['code' => '652', 'name' => 'Conveyance', 'calculation' => SalaryType::PERCENT_OF_BASIC, 'value' => 10, 'description' => 'Travel to and from work', 'is_active' => true],
                    ],
                ],
                'unique' => [['name']],
                'columns' => [
                    ['key' => 'code', 'label' => 'Code', 'type' => 'text', 'strong' => true],
                    ['key' => 'name', 'label' => 'Name', 'type' => 'text'],
                    ['key' => 'calculation', 'label' => 'Calculation', 'type' => 'badge'],
                    ['key' => 'value', 'label' => 'Rate', 'type' => 'rate', 'by' => 'calculation', 'percent' => SalaryType::PERCENT_OF_BASIC],
                    ['key' => 'description', 'label' => 'Description', 'type' => 'text', 'muted' => true],
                    self::statusColumn(),
                ],
                'fields' => [
                    array_merge(self::codeField(), ['placeholder' => 'e.g. 120', 'help' => 'Unique. Numbers and small letters only, with at least one number. Grades and imports refer to this salary type by its code.']),
                    ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'maxlength' => 255, 'placeholder' => 'e.g. House Rent'],
                    ['key' => 'calculation', 'label' => 'Calculation', 'type' => 'select', 'required' => true, 'default' => SalaryType::PERCENT_OF_BASIC, 'options' => self::CALCULATIONS],
                    ['key' => 'value', 'label' => 'Value', 'type' => 'number', 'required' => true, 'default' => 0, 'min' => 0, 'step' => 0.01, 'help' => 'The percentage of basic salary (10 means 10%), or the amount when the calculation is Fixed amount.'],
                    ['key' => 'description', 'label' => 'Description', 'type' => 'textarea', 'full' => true],
                    self::activeField(),
                ],
                'rules' => fn (?int $id, array $input) => [
                    'code' => self::codeRules('salary_types', $id),
                    'name' => ['required', 'string', 'max:255', self::uniqueName(SalaryType::class, $id, 'Salary type')],
                    'calculation' => ['required', Rule::in(array_column(self::CALCULATIONS, 'value'))],
                    'value' => [
                        'required', 'numeric', 'min:0', 'max:9999999999.99',
                        function (string $attribute, mixed $value, \Closure $fail) use ($input) {
                            if (($input['calculation'] ?? null) === SalaryType::PERCENT_OF_BASIC && is_numeric($value) && $value > 100) {
                                $fail('A percentage of basic cannot be more than 100.');
                            }
                        },
                    ],
                    'description' => ['nullable', 'string', 'max:2000'],
                    'is_active' => ['boolean'],
                ],
                'in_use' => function (Model $type): ?string {
                    $count = $type->grades()->count();

                    return $count ? "it is used by {$count} grade(s)" : null;
                },
            ],

            'grades' => [
                'label' => 'Grades & Pay Scales',
                'singular' => 'Grade',
                'description' => 'Pay grades with their basic salary range. The salary types ticked for a grade add their percentage (or fixed amount) on top of basic, which gives the gross range.',
                'permission' => 'grades',
                'model' => Grade::class,
                'title_key' => 'name',
                'order' => [['code', 'asc']],
                'import' => [
                    'match' => 'code',
                    'sheet' => 'Grades',
                    'aliases' => [
                        'code' => ['grade_code', 'grade'],
                        'name' => ['grade_name'],
                        'basic_min' => ['min_basic', 'basic_minimum', 'minimum_basic'],
                        'basic_max' => ['max_basic', 'basic_maximum', 'maximum_basic'],
                        'salary_type_ids' => ['salary_types', 'salary_type_codes', 'salary_type', 'components'],
                        'is_active' => ['status'],
                    ],
                    'examples' => [
                        ['code' => '301', 'name' => 'Grade 1 (Senior Management)', 'basic_min' => 80000, 'basic_max' => 120000, 'salary_type_ids' => ['120', '652'], 'is_active' => true],
                        ['code' => '305', 'name' => 'Grade 5 (Operator)', 'basic_min' => 8500, 'basic_max' => 9500, 'salary_type_ids' => ['120'], 'is_active' => true],
                    ],
                ],
                'columns' => [
                    ['key' => 'code', 'label' => 'Code', 'type' => 'text', 'strong' => true],
                    ['key' => 'name', 'label' => 'Name', 'type' => 'text'],
                    ['key' => 'basic_range', 'label' => 'Basic Salary Range', 'type' => 'range', 'from' => 'basic_min', 'to' => 'basic_max'],
                    ['key' => 'salary_type_ids', 'label' => 'Salary Types', 'type' => 'tags'],
                    ['key' => 'gross_range', 'label' => 'Gross Range', 'type' => 'range', 'from' => 'gross_min', 'to' => 'gross_max'],
                    self::statusColumn(),
                ],
                'fields' => [
                    self::codeField(),
                    ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true],
                    ['key' => 'basic_min', 'label' => 'Basic Salary (Min)', 'type' => 'number', 'required' => true, 'min' => 0, 'step' => 0.01],
                    ['key' => 'basic_max', 'label' => 'Basic Salary (Max)', 'type' => 'number', 'required' => true, 'min' => 0, 'step' => 0.01],
                    [
                        'key' => 'salary_type_ids',
                        'label' => 'Salary Types',
                        'heading' => 'Salary Type Codes',
                        'type' => 'multiselect',
                        'full' => true,
                        'relation' => 'salaryTypes',
                        'help' => 'Tick what this grade is paid on top of basic. The amounts below are worked out from each salary type\'s rate. Manage them under Salary Types.',
                        'preselect_active' => true,
                        'breakdown' => ['min' => 'basic_min', 'max' => 'basic_max'],
                        'lookup' => [
                            'model' => SalaryType::class,
                            'label' => fn (Model $m) => "{$m->code} — {$m->name}",
                            'order' => 'code',
                            'code' => 'code',
                            'page' => 'Salary Types',
                            'extra' => fn (Model $m) => ['calculation' => $m->calculation, 'rate' => (float) $m->value],
                        ],
                    ],
                    self::activeField(),
                ],
                'rules' => fn (?int $id, array $input) => [
                    'code' => self::codeRules('grades', $id),
                    'name' => ['required', 'string', 'max:255'],
                    'basic_min' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
                    'basic_max' => ['required', 'numeric', 'min:0', 'max:9999999999.99', 'gte:basic_min'],
                    'salary_type_ids' => ['nullable', 'array', 'max:50'],
                    'salary_type_ids.*' => ['integer', 'distinct', Rule::exists('salary_types', 'id')],
                    'is_active' => ['boolean'],
                ],
                'computed' => fn (Model $grade) => [
                    'gross_min' => $grade->grossAt($grade->basic_min),
                    'gross_max' => $grade->grossAt($grade->basic_max),
                ],
            ],

            'leave-types' => [
                'label' => 'Leave Types',
                'singular' => 'Leave Type',
                'description' => 'Leave categories with their entitlement, accrual and carry-forward rules.',
                'permission' => 'leave types',
                'model' => LeaveType::class,
                'title_key' => 'name',
                'order' => [['code', 'asc']],
                'import' => [
                    'match' => 'code',
                    'sheet' => 'Leave Types',
                    'aliases' => [
                        'code' => ['leave_code'],
                        'name' => ['leave_type', 'leave_name'],
                        'is_paid' => ['paid'],
                        'entitlement_days' => ['entitlement', 'days', 'days_per_year'],
                        'carry_forward' => ['carry_forward_allowed'],
                        'carry_forward_limit_days' => ['carry_forward_limit', 'max_carry_forward'],
                        'attachment_required' => ['attachment'],
                        'is_active' => ['status'],
                    ],
                    'examples' => [
                        ['code' => '401', 'name' => 'Casual Leave', 'is_paid' => true, 'entitlement_days' => 10, 'accrual' => 'yearly', 'carry_forward' => false, 'carry_forward_limit_days' => '', 'attachment_required' => false, 'is_active' => true],
                        ['code' => '402', 'name' => 'Earned Leave', 'is_paid' => true, 'entitlement_days' => 14, 'accrual' => 'monthly', 'carry_forward' => true, 'carry_forward_limit_days' => 40, 'attachment_required' => false, 'is_active' => true],
                    ],
                    'warn_duplicates' => ['name'],
                ],
                'columns' => [
                    ['key' => 'code', 'label' => 'Code', 'type' => 'text', 'strong' => true],
                    ['key' => 'name', 'label' => 'Name', 'type' => 'text'],
                    ['key' => 'is_paid', 'label' => 'Pay', 'type' => 'boolean', 'labels' => ['Paid', 'Unpaid'], 'colors' => ['green', 'gray']],
                    ['key' => 'entitlement_days', 'label' => 'Entitlement (days/yr)', 'type' => 'number'],
                    ['key' => 'accrual', 'label' => 'Accrual', 'type' => 'badge'],
                    ['key' => 'carry_forward', 'label' => 'Carry-forward', 'type' => 'boolean', 'labels' => ['Yes', 'No'], 'colors' => ['blue', 'gray']],
                    ['key' => 'attachment_required', 'label' => 'Attachment', 'type' => 'boolean', 'labels' => ['Required', 'Not required'], 'colors' => ['yellow', 'gray']],
                    self::statusColumn(),
                ],
                'fields' => [
                    self::codeField(),
                    ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true],
                    ['key' => 'is_paid', 'label' => 'Paid leave', 'type' => 'boolean', 'default' => true],
                    ['key' => 'entitlement_days', 'label' => 'Entitlement (days per year)', 'type' => 'number', 'required' => true, 'default' => 0, 'min' => 0, 'step' => 0.5, 'help' => 'Use 0 when there is no fixed yearly entitlement.'],
                    ['key' => 'accrual', 'label' => 'Accrual', 'type' => 'select', 'required' => true, 'default' => 'yearly', 'options' => self::ACCRUAL],
                    ['key' => 'carry_forward', 'label' => 'Unused days carry forward', 'type' => 'boolean', 'default' => false],
                    ['key' => 'carry_forward_limit_days', 'label' => 'Max carry-forward days', 'type' => 'number', 'min' => 0, 'step' => 0.5, 'show_if' => ['carry_forward' => true], 'help' => 'Leave blank for no cap.'],
                    ['key' => 'attachment_required', 'label' => 'Supporting attachment required', 'type' => 'boolean', 'default' => false],
                    self::activeField(),
                ],
                'rules' => fn (?int $id, array $input) => [
                    'code' => self::codeRules('leave_types', $id),
                    'name' => ['required', 'string', 'max:255'],
                    'is_paid' => ['boolean'],
                    'entitlement_days' => ['required', 'numeric', 'min:0', 'max:366'],
                    'accrual' => ['required', Rule::in(array_column(self::ACCRUAL, 'value'))],
                    'carry_forward' => ['boolean'],
                    'carry_forward_limit_days' => ['nullable', 'numeric', 'min:0', 'max:366'],
                    'attachment_required' => ['boolean'],
                    'is_active' => ['boolean'],
                ],
                'prepare' => function (array $data): array {
                    // A cap only means something while carry-forward is on.
                    if (empty($data['carry_forward'])) {
                        $data['carry_forward_limit_days'] = null;
                    }

                    return $data;
                },
            ],

            'offence-types' => [
                'label' => 'Offence Types',
                'singular' => 'Offence Type',
                'description' => 'Disciplinary offences and how severe each one is.',
                'permission' => 'offence types',
                'model' => OffenceType::class,
                'title_key' => 'name',
                'order' => [['code', 'asc']],
                'import' => [
                    'match' => 'code',
                    'sheet' => 'Offence Types',
                    'aliases' => [
                        'code' => ['offence_code'],
                        'name' => ['offence_type', 'name'],
                        'is_active' => ['status'],
                    ],
                    'examples' => [
                        ['code' => '501', 'name' => 'Habitual late attendance', 'severity' => 'minor', 'description' => 'Late more than three times in a month', 'is_active' => true],
                        ['code' => '502', 'name' => 'Theft or fraud', 'severity' => 'gross', 'description' => 'Taking company or colleagues\' property', 'is_active' => true],
                    ],
                    'warn_duplicates' => ['name'],
                ],
                'columns' => [
                    ['key' => 'code', 'label' => 'Code', 'type' => 'text', 'strong' => true],
                    ['key' => 'name', 'label' => 'Offence', 'type' => 'text'],
                    ['key' => 'severity', 'label' => 'Severity', 'type' => 'badge'],
                    self::statusColumn(),
                ],
                'fields' => [
                    self::codeField(),
                    ['key' => 'name', 'label' => 'Offence', 'type' => 'text', 'required' => true],
                    ['key' => 'severity', 'label' => 'Severity', 'type' => 'select', 'required' => true, 'default' => 'minor', 'options' => self::SEVERITY],
                    ['key' => 'description', 'label' => 'Description', 'type' => 'textarea', 'full' => true],
                    self::activeField(),
                ],
                'rules' => fn (?int $id, array $input) => [
                    'code' => self::codeRules('offence_types', $id),
                    'name' => ['required', 'string', 'max:255'],
                    'severity' => ['required', Rule::in(array_column(self::SEVERITY, 'value'))],
                    'description' => ['nullable', 'string', 'max:2000'],
                    'is_active' => ['boolean'],
                ],
            ],

            'acr-templates' => [
                'label' => 'ACR Templates',
                'singular' => 'ACR Template',
                'description' => 'Annual Confidential Report templates: weighted criteria, rating scale and the designations they apply to.',
                'permission' => 'acr templates',
                'model' => AcrTemplate::class,
                'title_key' => 'name',
                'order' => [['code', 'asc']],
                'columns' => [
                    ['key' => 'code', 'label' => 'Code', 'type' => 'text', 'strong' => true],
                    ['key' => 'name', 'label' => 'Name', 'type' => 'text'],
                    ['key' => 'criteria', 'label' => 'Criteria', 'type' => 'count', 'suffix' => 'criteria'],
                    ['key' => 'rating_scale', 'label' => 'Rating Scale', 'type' => 'count', 'suffix' => 'bands'],
                    ['key' => 'designation_ids', 'label' => 'Applies To', 'type' => 'tags'],
                    self::statusColumn(),
                ],
                'fields' => [
                    self::codeField(),
                    ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true],
                    [
                        'key' => 'criteria',
                        'label' => 'Criteria & Weights',
                        'type' => 'repeater',
                        'full' => true,
                        'required' => true,
                        'help' => 'Each criterion carries a weight. Weights must add up to 100.',
                        'add_label' => 'Add criterion',
                        'total' => ['key' => 'weight', 'label' => 'Total weight', 'target' => 100],
                        'item_default' => ['name' => '', 'weight' => ''],
                        'item_fields' => [
                            ['key' => 'name', 'label' => 'Criterion', 'type' => 'text', 'placeholder' => 'e.g. Integrity'],
                            ['key' => 'weight', 'label' => 'Weight', 'type' => 'number', 'min' => 0, 'max' => 100, 'step' => 0.01],
                        ],
                    ],
                    [
                        'key' => 'rating_scale',
                        'label' => 'Rating Scale',
                        'type' => 'repeater',
                        'full' => true,
                        'required' => true,
                        'help' => 'Rating bands over the weighted total score (0 to 100). Bands must not overlap.',
                        'add_label' => 'Add rating',
                        'item_default' => ['label' => '', 'min_score' => '', 'max_score' => ''],
                        'item_fields' => [
                            ['key' => 'label', 'label' => 'Rating', 'type' => 'text', 'placeholder' => 'e.g. Outstanding'],
                            ['key' => 'min_score', 'label' => 'From', 'type' => 'number', 'min' => 0, 'max' => 100, 'step' => 0.01],
                            ['key' => 'max_score', 'label' => 'To', 'type' => 'number', 'min' => 0, 'max' => 100, 'step' => 0.01],
                        ],
                    ],
                    [
                        'key' => 'designation_ids',
                        'label' => 'Applicable Designations',
                        'type' => 'multiselect',
                        'full' => true,
                        'relation' => 'designations',
                        'lookup' => [
                            'model' => Designation::class,
                            'label' => fn (Model $m) => "{$m->code} — {$m->title}",
                            'order' => 'serial',
                        ],
                    ],
                    self::activeField(),
                ],
                'rules' => fn (?int $id, array $input) => [
                    'code' => self::codeRules('acr_templates', $id),
                    'name' => ['required', 'string', 'max:255'],
                    'criteria' => [
                        'bail', 'required', 'array', 'min:1', 'max:30',
                        function (string $attribute, mixed $value, \Closure $fail) {
                            $weights = array_column($value, 'weight');
                            if (count($weights) !== count($value) || array_filter($weights, fn ($w) => ! is_numeric($w))) {
                                return; // the per-row rules report the real problem
                            }
                            if (abs(array_sum($weights) - 100) > 0.001) {
                                $fail('The criteria weights must add up to 100 (currently '.round(array_sum($weights), 2).').');
                            }
                        },
                    ],
                    'criteria.*.name' => ['required', 'string', 'max:255'],
                    'criteria.*.weight' => ['required', 'numeric', 'gt:0', 'max:100'],
                    'rating_scale' => [
                        'bail', 'required', 'array', 'min:1', 'max:20',
                        function (string $attribute, mixed $value, \Closure $fail) {
                            $bands = [];
                            foreach ($value as $band) {
                                if (! is_numeric($band['min_score'] ?? null) || ! is_numeric($band['max_score'] ?? null)) {
                                    return; // the per-row rules report the real problem
                                }
                                if ($band['min_score'] > $band['max_score']) {
                                    $fail("Rating \"{$band['label']}\": 'From' cannot be higher than 'To'.");

                                    return;
                                }
                                $bands[] = [(float) $band['min_score'], (float) $band['max_score'], $band['label'] ?? ''];
                            }
                            usort($bands, fn ($a, $b) => $a[0] <=> $b[0]);
                            for ($i = 1; $i < count($bands); $i++) {
                                if ($bands[$i][0] <= $bands[$i - 1][1]) {
                                    $fail("The rating bands \"{$bands[$i - 1][2]}\" and \"{$bands[$i][2]}\" overlap.");

                                    return;
                                }
                            }
                        },
                    ],
                    'rating_scale.*.label' => ['required', 'string', 'max:100'],
                    'rating_scale.*.min_score' => ['required', 'numeric', 'min:0', 'max:100'],
                    'rating_scale.*.max_score' => ['required', 'numeric', 'min:0', 'max:100'],
                    'designation_ids' => ['nullable', 'array'],
                    'designation_ids.*' => ['integer', 'distinct', Rule::exists('designations', 'id')],
                    'is_active' => ['boolean'],
                ],
            ],

            'document-types' => [
                'label' => 'Document Types',
                'singular' => 'Document Type',
                'description' => 'Documents collected by the system, which module they belong to and whether they are mandatory.',
                'permission' => 'document types',
                'model' => DocumentType::class,
                'title_key' => 'name',
                'order' => [['module', 'asc'], ['name', 'asc']],
                'import' => [
                    'match' => 'code',
                    'sheet' => 'Document Types',
                    'aliases' => [
                        'code' => ['document_code', 'doc_code'],
                        'name' => ['document', 'document_type', 'document_name'],
                        'is_mandatory' => ['mandatory', 'required'],
                        'is_active' => ['status'],
                    ],
                    'examples' => [
                        ['code' => '601', 'name' => 'National ID Card', 'module' => 'employee_profile', 'is_mandatory' => true, 'is_active' => true],
                        ['code' => '602', 'name' => 'Curriculum Vitae (CV)', 'module' => 'recruitment', 'is_mandatory' => false, 'is_active' => true],
                    ],
                ],
                // The same document may be collected by two modules, but only once per module.
                'unique' => [['module', 'name']],
                'columns' => [
                    ['key' => 'code', 'label' => 'Code', 'type' => 'text', 'strong' => true],
                    ['key' => 'name', 'label' => 'Name', 'type' => 'text'],
                    ['key' => 'module', 'label' => 'Module', 'type' => 'badge'],
                    ['key' => 'is_mandatory', 'label' => 'Mandatory', 'type' => 'boolean', 'labels' => ['Yes', 'No'], 'colors' => ['red', 'gray']],
                    self::statusColumn(),
                ],
                'fields' => [
                    self::codeField(),
                    ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true],
                    ['key' => 'module', 'label' => 'Module', 'type' => 'select', 'required' => true, 'default' => 'employee_profile', 'options' => self::MODULES],
                    ['key' => 'is_mandatory', 'label' => 'Mandatory', 'type' => 'boolean', 'default' => false],
                    self::activeField(),
                ],
                'rules' => fn (?int $id, array $input) => [
                    'code' => self::codeRules('document_types', $id),
                    'name' => [
                        'required', 'string', 'max:255',
                        Rule::unique('document_types', 'name')
                            ->where('module', $input['module'] ?? null)
                            ->ignore($id),
                    ],
                    'module' => ['required', Rule::in(array_column(self::MODULES, 'value'))],
                    'is_mandatory' => ['boolean'],
                    'is_active' => ['boolean'],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function codeField(): array
    {
        return ['key' => 'code', 'label' => 'Code', 'type' => 'text', 'required' => true, 'lowercase' => true, 'maxlength' => Code::MAX_LENGTH, 'placeholder' => 'e.g. 101', 'help' => 'Unique. Numbers and small letters only, with at least one number.'];
    }

    /** @return array<string, mixed> */
    private static function activeField(): array
    {
        return ['key' => 'is_active', 'label' => 'Active', 'type' => 'boolean', 'default' => true];
    }

    /** @return array<string, mixed> */
    private static function statusColumn(): array
    {
        return ['key' => 'is_active', 'label' => 'Status', 'type' => 'boolean', 'labels' => ['Active', 'Inactive'], 'colors' => ['green', 'red']];
    }

    /**
     * "Human Resources" and "human resources" are the same name.
     *
     * @param  class-string<Model>  $model
     */
    private static function uniqueName(string $model, ?int $id, string $singular): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($model, $id, $singular) {
            $taken = is_string($value) && $model::query()
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($value)])
                ->when($id, fn ($q) => $q->whereKeyNot($id))
                ->exists();
            if ($taken) {
                $fail('A '.mb_strtolower($singular).' with this name already exists.');
            }
        };
    }

    /**
     * @param  array<string, mixed>  $scope  extra columns the code must be unique within
     * @return list<mixed>
     */
    private static function codeRules(string $table, ?int $id, array $scope = []): array
    {
        $unique = Rule::unique($table, 'code')->ignore($id);
        foreach ($scope as $column => $value) {
            $unique->where($column, $value); // e.g. a grade code is unique within its grade type
        }

        return ['required', 'string', 'max:'.Code::MAX_LENGTH, 'regex:'.Code::PATTERN, $unique];
    }
}
