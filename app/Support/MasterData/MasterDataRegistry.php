<?php

namespace App\Support\MasterData;

use App\Models\AcrTemplate;
use App\Models\Department;
use App\Models\Designation;
use App\Models\DesignationCategory;
use App\Models\DocumentType;
use App\Models\Grade;
use App\Models\LeaveType;
use App\Models\OffenceType;
use App\Models\PayScale;
use App\Models\Unit;
use App\Support\Payroll\Formula;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * Describes every HR master-data screen: its model, form fields, table
 * columns, validation and deletion guards. One generic controller and one
 * generic Vue page render all of them from these definitions, so adding a
 * master means adding an entry here (plus a table and a model).
 *
 * A text field may set `text` (Excel keeps its column as text, for digits that are not a number).
 * Field types: text, number, textarea, select (static `options`, or a `lookup` of another
 * master), boolean, multiselect, repeater.
 * Column types: text, number, badge, boolean, count, tags, list, calculations, rate.
 *
 * A multiselect's `lookup` may add `extra` (option data for the page), and the
 * field may set `preselect_active` (tick every active option on a new record).
 * A field may set `variants` (['by' => other field's key, 'options' => [value => overrides]]):
 * the form then relabels it, and changes its limits and hints, by what the other field holds.
 * A master may set `computed`: a closure returning extra values to send with each row (a
 * `list` column shows such a list of names, a `calculations` column becomes one column per
 * calculation title), and `with`: relations to load for them.
 * `pay_scales` / `designation_categories`: the page also lists, adds and edits the salary formulas /
 * designation categories (their own controllers, same permissions as the master).
 *
 * Uniqueness: every master's `code` (numbers and small letters only, see Code)
 * (or its `import.match` fields) identifies a record, has a unique index in the
 * database and is how other records will refer to it later (an employee's department code, a grade's grade type code).
 * `unique` lists any other column sets that must not repeat, compared ignoring
 * case; the form rules enforce them against saved data and the import also
 * checks them between the rows of one file. Tests check each has a unique index.
 *
 * Trash: every master's model uses SoftDeletes, so deleting moves a record to the Trash (MasterDataController
 * lists it with `?trash=1`; bulk() trashes, restores or deletes ticked rows). A trashed record keeps its code and
 * name, `in_use` blocks trashing and deleting for good, and the import refuses a code that is in the Trash.
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
 *    comma-separated list of codes (e.g. an ACR template's designations).
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
        'unit_code.regex' => Code::UNIT_MESSAGE,
    ];

    /** The type of a grade: whose pay scale it is. "65 Ages" covers employees aged 65 or over. */
    public const GRADE_TYPES = [
        ['value' => Grade::GROUP_MALE, 'label' => 'Group - Male', 'color' => 'blue'],
        ['value' => Grade::GROUP_FEMALE_65, 'label' => 'Group - Female or 65 Ages', 'color' => 'purple'],
        ['value' => Grade::VBL_MALE, 'label' => 'VBL - Male', 'color' => 'green'],
        ['value' => Grade::VBL_FEMALE_65, 'label' => 'VBL - Female or 65 Ages', 'color' => 'orange'],
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
        return self::all()[$key] ?? self::standalone()[$key] ?? null;
    }

    /**
     * Masters with their own screen, routes and permissions, outside the generic page
     * (so not in all(): no generated routes, no role-matrix entry), that still use the
     * generic reviewed Excel import. Units: its page also shows each unit's users.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function standalone(): array
    {
        return ['units' => self::units()];
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /**
     * What a role can be allowed to do on a master. `manage` is full access and
     * stands in for every one of these; the rest are ticked one by one in the
     * role matrix. Import and export exist only on masters with Excel support.
     */
    public const ACTIONS = ['view', 'create', 'edit', 'delete', 'import', 'export'];

    /** Holidays have their own screen, not a registry entry, but the same permissions. */
    public const HOLIDAYS = 'holidays';

    /**
     * Permission names for one master, `manage` last.
     *
     * @param  list<string>  $without  actions the master does not have
     * @return list<string>
     */
    public static function permissionsOf(string $permission, array $without = []): array
    {
        $actions = array_diff(self::ACTIONS, $without);

        return [...array_map(fn (string $action) => "{$action} {$permission}", $actions), "manage {$permission}"];
    }

    /**
     * The permissions that let someone do $action on a master: its own, or full
     * access. Route middleware and controllers both use this, so they agree.
     *
     * @return list<string>
     */
    public static function allowing(string $permission, string $action): array
    {
        return ["{$action} {$permission}", "manage {$permission}"];
    }

    /**
     * Any permission on the master at all: enough to open its screen.
     *
     * @param  array<string, mixed>  $definition
     * @return list<string>
     */
    public static function opening(array $definition): array
    {
        return self::permissionsOf($definition['permission'], isset($definition['import']) ? [] : ['import', 'export']);
    }

    /** @return list<string> */
    public static function permissionNames(): array
    {
        $names = [];
        foreach (self::all() as $definition) {
            array_push($names, ...self::opening($definition));
        }

        return $names;
    }

    /** Holidays can be imported from Excel but not exported. @return list<string> */
    public static function holidayPermissionNames(): array
    {
        return self::permissionsOf(self::HOLIDAYS, without: ['export']);
    }

    /** Every master-data permission, the registry's and Holidays'. @return list<string> */
    public static function allPermissionNames(): array
    {
        return [...self::permissionNames(), ...self::holidayPermissionNames()];
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
                'description' => 'Job titles, grouped into categories (Merchandising, Machine Operators, ...). Each category is its own ladder: its designations are numbered from 1 on their own, and a lower serial is more senior.',
                'permission' => 'designations',
                'model' => Designation::class,
                'title_key' => 'title',
                // By category, then the category's own serial order.
                'order' => [['designation_category_id', 'asc'], ['serial', 'asc'], ['code', 'asc']],
                'filters' => ['designation_category_id'],
                // The page also lists, adds and edits the categories (see DesignationCategoryController).
                'designation_categories' => true,
                'import' => [
                    'match' => 'code',
                    'sheet' => 'Designations',
                    'aliases' => [
                        'code' => ['designation_code'],
                        'title' => ['designation', 'name', 'job_title'],
                        'designation_category_id' => ['category', 'designation_category', 'type', 'designation_type', 'group'],
                        'serial' => ['serial_no', 'sl', 'order', 'seniority'],
                        'is_active' => ['status'],
                    ],
                    'examples' => [
                        ['code' => '201', 'title' => 'Managing Director', 'designation_category_id' => 'General', 'serial' => 1, 'is_active' => true],
                        ['code' => '202', 'title' => 'Senior Executive', 'designation_category_id' => 'General', 'serial' => 12, 'is_active' => true],
                    ],
                    'warn_duplicates' => ['title'],
                ],
                'columns' => [
                    ['key' => 'designation_category_id', 'label' => 'Category', 'type' => 'badge'],
                    ['key' => 'serial', 'label' => 'Serial', 'type' => 'number'],
                    ['key' => 'code', 'label' => 'Code', 'type' => 'text', 'strong' => true],
                    ['key' => 'title', 'label' => 'Title', 'type' => 'text'],
                    self::statusColumn(),
                ],
                'fields' => [
                    self::codeField(),
                    ['key' => 'title', 'label' => 'Title', 'type' => 'text', 'required' => true],
                    [
                        'key' => 'designation_category_id',
                        'label' => 'Category',
                        'plural' => 'Categories',
                        // The sheet's column takes the category's name (Merchandising, ...), so it is called just "Category".
                        'heading' => 'Category',
                        'type' => 'select',
                        'required' => true,
                        'help' => 'Which kind of designation this is. Categories are set up under Designation Categories on this page.',
                        'lookup' => [
                            'model' => DesignationCategory::class,
                            'label' => fn (Model $m) => $m->name,
                            'code' => 'name',
                            'order' => 'id',
                            'page' => 'Designations (Designation Categories)',
                            'extra' => fn (Model $m) => ['color' => $m->color()],
                        ],
                    ],
                    ['key' => 'serial', 'label' => 'Serial', 'type' => 'number', 'required' => true, 'default' => 0, 'min' => 0, 'step' => 1, 'help' => 'Seniority order within its category: each category has its own 1, 2, 3 ... Lower is more senior.'],
                    self::activeField(),
                ],
                'rules' => fn (?int $id, array $input) => [
                    'code' => self::codeRules('designations', $id),
                    'title' => ['required', 'string', 'max:255'],
                    'designation_category_id' => ['required', 'integer', Rule::exists('designation_categories', 'id')],
                    'serial' => ['required', 'integer', 'min:0', 'max:65535'],
                    'is_active' => ['boolean'],
                ],
                'in_use' => function (Model $designation): ?string {
                    $count = $designation->acrTemplates()->count();

                    return $count ? "it is used by {$count} ACR template(s)" : null;
                },
            ],

            'grades' => [
                'label' => 'Grades & Pay Scales',
                'singular' => 'Grade',
                'description' => 'Pay grades and the salary formulas they are paid by. A grade is a number, its type and its formula. A formula is a set of components such as Basic, Gross and House Rent, each worked out the way you write it; add as many formulas as grades need.',
                'permission' => 'grades',
                'model' => Grade::class,
                'title_key' => 'code',
                'order' => [['code', 'asc']],
                'filters' => ['type', 'pay_scale_id'],
                // The page also lists, adds and edits the salary formulas (see GradePayScaleController).
                'pay_scales' => true,
                'with' => ['payScale.lines'],
                'import' => [
                    'match' => 'code',
                    'sheet' => 'Grades',
                    'aliases' => [
                        'code' => ['grade_code', 'grade', 'grade_number'],
                        'type' => ['grade_type', 'pay_scale_type', 'category'],
                        'pay_scale_id' => ['formula', 'salary_formula', 'formula_name', 'pay_scale'],
                        'is_active' => ['status'],
                    ],
                    'examples' => [
                        ['code' => '301', 'type' => Grade::GROUP_MALE, 'pay_scale_id' => 'Group', 'is_active' => true],
                        ['code' => '305', 'type' => Grade::VBL_FEMALE_65, 'pay_scale_id' => 'VBL', 'is_active' => true],
                    ],
                ],
                'columns' => [
                    ['key' => 'code', 'label' => 'Grade', 'type' => 'text', 'strong' => true],
                    ['key' => 'type', 'label' => 'Type', 'type' => 'badge'],
                    ['key' => 'pay_scale_id', 'label' => 'Formula', 'type' => 'badge'],
                    // One column per component title in use (Basic, Gross, House Rent, ...); the page expands it.
                    ['key' => 'calculations', 'label' => 'Salary', 'type' => 'calculations'],
                    self::statusColumn(),
                ],
                'fields' => [
                    array_merge(self::codeField(), ['label' => 'Grade', 'placeholder' => 'e.g. 400', 'help' => 'Unique. The grade number, such as 400. Salary formulas use it as [Grade], so it must be a plain number.']),
                    ['key' => 'type', 'label' => 'Type', 'type' => 'select', 'required' => true, 'options' => self::GRADE_TYPES, 'help' => 'Group or VBL, and male or female / 65 ages. The male / female or 65 part will decide the tax.'],
                    [
                        'key' => 'pay_scale_id',
                        'label' => 'Formula',
                        // The sheet's column takes the formula's name (Group, VBL, ...), so it is called just "Formula".
                        'heading' => 'Formula',
                        'type' => 'select',
                        'required' => true,
                        'help' => 'The salary formula this grade is paid by. Formulas are set up under Salary Formulas on this page.',
                        'lookup' => [
                            'model' => PayScale::class,
                            'label' => fn (Model $m) => $m->name,
                            'code' => 'name',
                            'order' => 'id',
                            'page' => 'Grades & Pay Scales (Salary Formulas)',
                        ],
                    ],
                    // Status is optional: a grade is Active unless it is unticked, and a blank Status in an import
                    // means Active for a new grade and "keep what is saved" for an existing one (the sheet's
                    // instructions say so themselves, from this field's default).
                    self::activeField(),
                ],
                'rules' => fn (?int $id, array $input) => [
                    'code' => [
                        ...self::codeRules('grades', $id),
                        // A formula with [Grade] multiplies the grade number, so it has to be a number.
                        function (string $attribute, mixed $value, \Closure $fail) use ($input) {
                            $id = $input['pay_scale_id'] ?? null;
                            $payScale = is_numeric($id) ? PayScale::with('lines')->find((int) $id) : null;

                            if ($payScale?->usesGradeNumber() && is_string($value) && $value !== '' && ! ctype_digit($value)) {
                                $fail("The {$payScale->name} formula uses the grade number, so the grade must be a number such as 400.");
                            }
                        },
                    ],
                    'type' => ['required', Rule::in(array_column(self::GRADE_TYPES, 'value'))],
                    'pay_scale_id' => ['required', 'integer', Rule::exists('pay_scales', 'id')],
                    'is_active' => ['boolean'],
                ],
                'computed' => fn (Model $grade) => [
                    // Amount per component title (as formulas name it, to match the page's columns),
                    // e.g. ['basic' => 10950, 'house rent' => 5475, 'gross' => 18425].
                    'calculations' => collect($grade->calculations())->mapWithKeys(fn (array $c) => [Formula::key($c['title']) => $c['value']])->all(),
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
                            'label' => fn (Model $m) => "{$m->code} — {$m->title} ({$m->category->name})",
                            'with' => ['category'],
                            'order' => ['designation_category_id', 'serial'],
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

    /**
     * Units: the factories and offices, identified by a code like F02-PDD. The Units screen
     * (UnitController) lists and edits them; only the Excel import goes through the generic
     * importer, so this has no columns. Its import is part of adding units (`create units`) and its export of viewing them (`view units`).
     *
     * @return array<string, mixed>
     */
    private static function units(): array
    {
        return [
            'label' => 'Units',
            'singular' => 'Unit',
            'description' => 'Factories and offices, each identified by a unit code.',
            'permission' => 'units',
            'permissions' => ['import' => 'create', 'export' => 'view'],
            'model' => Unit::class,
            'title_key' => 'name',
            'order' => [['unit_code', 'asc']],
            'import' => [
                'match' => 'unit_code',
                'code' => 'unit_code',
                'code_format' => 'unit',
                'sheet' => 'Units',
                'aliases' => [
                    'unit_code' => ['code', 'unit_no', 'unit_number'],
                    'short_form' => ['short_name', 'short'],
                    'name' => ['unit', 'unit_name'],
                    'location' => ['address'],
                    'contact_person' => ['contact', 'contact_name'],
                    'phone_number' => ['phone', 'mobile', 'contact_number'],
                    'email' => ['e_mail', 'email_address'],
                    'is_active' => ['status'],
                ],
                'examples' => [
                    ['unit_code' => 'F01-HO', 'short_form' => 'HO', 'name' => 'Head Office', 'location' => 'Dhaka, Bangladesh', 'contact_person' => 'John Doe', 'phone_number' => '01712345678', 'email' => 'headoffice@example.com', 'is_active' => true],
                    ['unit_code' => 'F02-CTG', 'short_form' => 'CTG', 'name' => 'Chittagong Factory', 'location' => 'Chittagong, Bangladesh', 'contact_person' => 'Jane Smith', 'phone_number' => '01798765432', 'email' => '', 'is_active' => true],
                ],
                'warn_duplicates' => ['short_form'],
            ],
            'fields' => [
                ['key' => 'unit_code', 'label' => 'Unit Code', 'type' => 'text', 'text' => true, 'required' => true, 'maxlength' => Code::MAX_LENGTH, 'placeholder' => 'e.g. F02-PDD', 'help' => 'Letters, numbers and hyphens, with at least one number (e.g. F02-PDD). Capitals stay as typed, and capitals and small letters count as the same code.'],
                ['key' => 'short_form', 'label' => 'Short Form', 'type' => 'text', 'maxlength' => 50],
                ['key' => 'name', 'label' => 'Unit Name', 'type' => 'text', 'required' => true, 'maxlength' => 255],
                ['key' => 'location', 'label' => 'Location', 'type' => 'text', 'maxlength' => 255],
                ['key' => 'contact_person', 'label' => 'Contact Person', 'type' => 'text', 'maxlength' => 255],
                ['key' => 'phone_number', 'label' => 'Phone Number', 'type' => 'text', 'text' => true, 'maxlength' => 20],
                ['key' => 'email', 'label' => 'Email', 'type' => 'text', 'maxlength' => 255],
                self::activeField(),
            ],
            'rules' => fn (?int $id, array $input) => Unit::rules($id),
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
            // A record in the Trash still holds its name (the table keeps it unique).
            $holder = is_string($value) ? $model::withTrashed()
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($value)])
                ->when($id, fn ($q) => $q->whereKeyNot($id))
                ->first() : null;
            if ($holder?->trashed()) {
                $fail('A '.mb_strtolower($singular).' with this name is in the Trash. Restore it, or delete it permanently, first.');
            } elseif ($holder) {
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
