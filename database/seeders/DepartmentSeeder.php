<?php

namespace Database\Seeders;

use App\Models\Department;
use Illuminate\Database\Seeder;

class DepartmentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $departments = [
            [
                'name' => 'Human Resources',
                'short_name' => 'HR',
                'code' => 'd001',
                'description' => 'Recruitment, employee relations, and workforce management',
                'is_active' => true,
            ],
            [
                'name' => 'Internal Audit',
                'short_name' => 'IA',
                'code' => 'd002',
                'description' => 'Internal social compliance audits and corrective action follow-up',
                'is_active' => true,
            ],
            [
                'name' => 'Establishment',
                'short_name' => 'EST',
                'code' => 'd003',
                'description' => 'Establishment and administrative department',
                'is_active' => true,
            ],
            [
                'name' => 'Purchase Department',
                'short_name' => 'PUR',
                'code' => 'd004',
                'description' => 'Procurement and purchasing department',
                'is_active' => true,
            ],
            [
                'name' => 'Accounts Department',
                'short_name' => 'ACC',
                'code' => 'd005',
                'description' => 'Finance and accounting department',
                'is_active' => true,
            ],
            [
                'name' => 'Top Management',
                'short_name' => 'TM',
                'code' => 'd006',
                'description' => 'Senior management and executive department',
                'is_active' => true,
            ],
        ];

        foreach ($departments as $department) {
            Department::updateOrCreate(['name' => $department['name']], $department);
        }

        $this->command->info('Default departments seeded successfully.');
    }
}
