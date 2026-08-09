<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Unit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DemoUnitSeeder extends Seeder
{
    public function run(): void
    {
        $units = [
            [
                'unit_code' => 'U000101',
                'name' => 'Ventura (BD) Limited',
                'location' => 'Karnaphuli EPZ',
                'contact_person' => 'Mr. Krishna Pada Das (ED)',
                'phone_number' => '01755-636888',
            ],
            [
                'unit_code' => 'U000001',
                'name' => 'Clifton Apparels Ltd',
                'location' => 'Baizid Complex',
                'contact_person' => 'Mr. Md. Nurul Islam - GM',
                'phone_number' => '01822-321133',
            ],
            [
                'unit_code' => 'U000002',
                'name' => 'Clifton Textile & Apparels Limited',
                'location' => 'Baizid Complex',
                'contact_person' => 'Mr. Abdul Hai Faruki - GM',
                'phone_number' => '01712-205472',
            ],
            [
                'unit_code' => 'U000004',
                'name' => 'Deluxe Fashions Ltd.',
                'location' => 'Baizid Complex',
                'contact_person' => 'Mr. Sabbir Ahmed - GM',
                'phone_number' => '01710-522161',
            ],
            [
                'unit_code' => 'U000003',
                'name' => 'Clifton Cotton Mills Limited',
                'location' => 'Kalurghat I/A',
                'contact_person' => 'Mr. Noor Nabi Pataway - GM',
                'phone_number' => '01817-292520',
            ],
            [
                'unit_code' => 'U000173',
                'name' => 'Kith Fashion Limited',
                'location' => 'Kalurghat I/A',
                'contact_person' => 'Mr. Md. Mominul Hoque - DGM',
                'phone_number' => '01817-292567',
            ],
        ];

        foreach ($units as $unit) {
            Unit::updateOrCreate(
                ['unit_code' => $unit['unit_code']],
                array_merge($unit, [
                    'is_active' => true,
                    'created_by' => 1,
                    'email' => null,
                ])
            );
        }
    }
}
