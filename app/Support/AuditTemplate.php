<?php

namespace App\Support;

/**
 * Fixed vocabulary and default rows of the "Internal Audit Report & CAP" form.
 * New audits are pre-filled from here; auditors may add extra rows per audit.
 */
class AuditTemplate
{
    public const STANDARDS = ['Social Compliance', 'C-TPAT', 'COC'];

    public const TYPES = [
        'initial' => 'Initial',
        'follow_up' => 'Follow Up',
    ];

    public const CATEGORIES = [
        'Workers Basic Rights',
        'Compensation',
        'Contracts, Human Rights & Grievance',
        'Occupational Health & Safety',
        'Chemical Management',
        'Training',
        'Child Labor',
        'Forced Labor',
        'TU/PC',
        'Safety Committee',
        'Other',
    ];

    // Ordered most to least severe; 'compliant' records a positive statement with no CAP.
    public const RATINGS = [
        'zero_tolerance' => 'Zero Tolerance',
        'critical' => 'Critical',
        'major' => 'Major',
        'minor' => 'Minor',
        'observation' => 'Observation',
        'compliant' => 'Compliant',
    ];

    public const RESULTS = [
        'excellent' => ['label' => 'Excellent', 'color' => 'Green'],
        'satisfactory' => ['label' => 'Satisfactory', 'color' => 'Yellow'],
        'need_improvement' => ['label' => 'Need Improvement', 'color' => 'Orange'],
        'continuous_improvement' => ['label' => 'Continuous Improvement', 'color' => null],
        'zero_tolerance' => ['label' => 'Zero Tolerance', 'color' => 'Red'],
    ];

    public static function manpower(): array
    {
        $rows = fn (array $designations) => array_map(
            fn ($designation) => ['designation' => $designation, 'count' => null],
            $designations
        );

        return [
            'workers' => $rows([
                'Operator (all Grade)',
                'Asst. Operator (Sewing section)',
                'Cutting Section',
                'Packer/Finishing',
                'QI (Sewing & Finishing)',
                'Peon, Checker, Cleaner, sweeper',
                'Maintenance/Electrical workers',
                'Others',
            ]),
            'staff' => $rows([
                'Store staffs',
                'Commercial staffs',
                'Admin, HR-Compliance, Personnel staffs',
                'Production staffs (Cutting/Quality/Finishing/sewing)',
                'Maintenance/Electrical staffs',
                'Welfare & I.E',
            ]),
        ];
    }

    public static function floorLayout(): array
    {
        return array_map(
            fn ($floor) => ['floor' => $floor, 'usage' => null],
            ['Ground Floor', '1st Floor', '2nd Floor', '3rd Floor', '4th Floor', '5th Floor', '6th Floor', 'Sheds 1-5']
        );
    }

    public static function legalDocuments(): array
    {
        return array_map(
            fn ($name) => ['name' => $name, 'status' => null, 'valid_until' => null, 'remarks' => null],
            [
                'Factory License',
                'Fire License',
                'Trade License',
                'Bond License',
                'Generator License/Generator Waiver/NOC',
                'Captive Power Plant (CPP)',
                'Boiler License',
                'Group Insurance',
                'BGMEA Membership',
                'Environment Certificate',
                'Incorporation certificate',
                'ERC/IRC',
                'EPB',
                'Drinking Water Test Report',
                'Boiler Operator Certificate',
                'Fire Insurance',
                'Electrician competency certificate',
                'Building Approval Plan',
                'Factory layout plan',
            ]
        );
    }

    public static function fireEquipment(): array
    {
        return array_map(
            fn ($name) => ['name' => $name, 'quantity' => null, 'remarks' => null],
            [
                'DCP Extinguisher',
                'Co2 Extinguisher',
                'Foam Type extinguisher',
                'Gas Mask',
                'Fire Blanket',
                'Fire Bucket',
                'Helmet',
                'Hand Gloves',
                'Gong Bell',
                'Torch light',
                'Emergency light',
                'Exit light box',
                'First Aid Box',
                'Stretcher',
                'Lock Cutter',
                'Sprinkler Head',
            ]
        );
    }

    /** Vocabulary the Vue pages need to render selects, badges and defaults. */
    public static function forFrontend(): array
    {
        return [
            'standards' => self::STANDARDS,
            'types' => self::TYPES,
            'categories' => self::CATEGORIES,
            'ratings' => self::RATINGS,
            'results' => self::RESULTS,
        ];
    }
}
