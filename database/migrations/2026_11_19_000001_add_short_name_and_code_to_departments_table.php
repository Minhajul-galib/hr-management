<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The built-in departments get their real short name / code here (not only in
     * DepartmentSeeder) because production never runs the full seeder.
     */
    private const KNOWN = [
        'Human Resources' => ['short_name' => 'HR', 'code' => 'D001'],
        'Internal Audit' => ['short_name' => 'IA', 'code' => 'D002'],
        'Establishment' => ['short_name' => 'EST', 'code' => 'D003'],
        'Purchase Department' => ['short_name' => 'PUR', 'code' => 'D004'],
        'Accounts Department' => ['short_name' => 'ACC', 'code' => 'D005'],
        'Top Management' => ['short_name' => 'TM', 'code' => 'D006'],
    ];

    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->string('short_name', 50)->nullable()->after('name');
            $table->string('code', 50)->nullable()->unique()->after('short_name');
        });

        $this->backfill();

        // Every department now has both, so make them mandatory from here on.
        Schema::table('departments', function (Blueprint $table) {
            $table->string('short_name', 50)->nullable(false)->change();
            $table->string('code', 50)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn(['short_name', 'code']);
        });
    }

    /** Give every existing department a short name and a unique code. */
    private function backfill(): void
    {
        $taken = [];
        foreach (self::KNOWN as $values) {
            $taken[$values['code']] = true;
        }

        foreach (DB::table('departments')->orderBy('id')->get(['id', 'name']) as $department) {
            if (isset(self::KNOWN[$department->name])) {
                $values = self::KNOWN[$department->name];
            } else {
                // Custom department: initials of the name, and the next free D### code.
                $initials = collect(preg_split('/[^A-Za-z0-9]+/', $department->name, -1, PREG_SPLIT_NO_EMPTY))
                    ->map(fn ($word) => strtoupper($word[0]))
                    ->implode('');

                $number = 1;
                while (isset($taken[$code = sprintf('D%03d', $number)])) {
                    $number++;
                }
                $taken[$code] = true;

                $values = ['short_name' => substr($initials, 0, 50) ?: $code, 'code' => $code];
            }

            DB::table('departments')->where('id', $department->id)->update($values);
        }
    }
};
