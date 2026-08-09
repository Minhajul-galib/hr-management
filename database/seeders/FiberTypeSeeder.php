<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FiberTypeSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            [
                'fiber_description' => '100% Cotton Combed Compact',
                'name'              => 'Conventional',
                'excel_description' => '100% Ctn Cmb Cmpt',
            ],
            [
                'fiber_description' => 'CVC - 60% Cotton X 40% Polyester',
                'name'              => 'CVC Regular',
                'excel_description' => 'CVC 60/40 Ctn/Poly',
            ],
            [
                'fiber_description' => '100% BCI Cotton Combed Compact',
                'name'              => 'BCI',
                'excel_description' => '100% BCI Ctn Cmb Cmpt',
            ],
            [
                'fiber_description' => 'CVC - 60% Cotton X 40% Recycled Polyester',
                'name'              => 'CVC - Recycled',
                'excel_description' => 'CVC 60/40 Ctn/ Recycled Poly',
            ],
            [
                'fiber_description' => '100% Organic Cotton Combed Compact (NOP GOTS)',
                'name'              => 'Organic',
                'excel_description' => '100% Organic Ctn Cmb Cmpt (NOP GOTS)',
            ],
        ];

        foreach ($rows as $row) {
            DB::table('fiber_types')->updateOrInsert(
                ['name' => $row['name']],
                array_merge($row, [
                    'is_active'  => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }
    }
}
