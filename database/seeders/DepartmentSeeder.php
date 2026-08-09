<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Department;

class DepartmentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $departments = [
            [
                'name' => 'Establishment',
                'description' => 'Establishment and administrative department',
                'is_active' => true,
            ],
            [
                'name' => 'Purchase Department',
                'description' => 'Procurement and purchasing department',
                'is_active' => true,
            ],
            [
                'name' => 'Accounts Department',
                'description' => 'Finance and accounting department',
                'is_active' => true,
            ],
            [
                'name' => 'Top Management',
                'description' => 'Senior management and executive department',
                'is_active' => true,
            ],
        ];

        foreach ($departments as $department) {
            Department::create($department);
        }

        $this->command->info('Default departments seeded successfully.');
    }
}
