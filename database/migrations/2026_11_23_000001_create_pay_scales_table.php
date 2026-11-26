<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a grade is paid depends on its type (Group - Male, VBL - Female or 65 Ages, ...), not
 * on the grade alone. Each type has one pay scale:
 *
 *  - the basic salary formula: basic = grade number x multiplier + amount (Group - Male is
 *    grade x 25 + 950, so grade 400 has a basic of 10,950; the other types have their own), and
 *  - the salary types connected to it (house rent, conveyance, ...), paid on top of basic.
 *
 * The salary types used to be ticked grade by grade. They now belong to the type, so a grade
 * takes them from its type; whatever was ticked on the grades of a type is carried over to
 * that type's pay scale (every salary type any of its grades had), and the per-grade link
 * table is dropped.
 *
 * Group - Male is set to the formula it was given (25 and 950). The other three are left
 * blank until their formula is entered on the Grades & Pay Scales page.
 */
return new class extends Migration
{
    private const TYPES = ['group_male', 'group_female_65', 'vbl_male', 'vbl_female_65'];

    public function up(): void
    {
        Schema::create('pay_scales', function (Blueprint $table) {
            $table->id();
            $table->string('type', 30)->unique();
            // basic = grade number x basic_multiplier + basic_addend; both blank until the formula is set.
            $table->decimal('basic_multiplier', 12, 2)->nullable();
            $table->decimal('basic_addend', 12, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('pay_scale_salary_type', function (Blueprint $table) {
            $table->foreignId('pay_scale_id')->constrained()->cascadeOnDelete();
            // A salary type that a pay scale still uses cannot be deleted.
            $table->foreignId('salary_type_id')->constrained()->restrictOnDelete();
            $table->primary(['pay_scale_id', 'salary_type_id']);
            $table->index('salary_type_id');
        });

        $now = now();
        foreach (self::TYPES as $type) {
            DB::table('pay_scales')->insert([
                'type' => $type,
                'basic_multiplier' => $type === 'group_male' ? 25 : null,
                'basic_addend' => $type === 'group_male' ? 950 : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // Keep what was ticked on grades: each type gets every salary type its grades had.
        DB::statement('
            INSERT INTO pay_scale_salary_type (pay_scale_id, salary_type_id)
            SELECT DISTINCT pay_scales.id, grade_salary_type.salary_type_id
            FROM grade_salary_type
            JOIN grades ON grades.id = grade_salary_type.grade_id
            JOIN pay_scales ON pay_scales.type = grades.type
        ');

        Schema::dropIfExists('grade_salary_type');
    }

    /** Brings back the (empty) per-grade link; the salary types are not copied back onto grades. */
    public function down(): void
    {
        Schema::create('grade_salary_type', function (Blueprint $table) {
            $table->foreignId('grade_id')->constrained()->cascadeOnDelete();
            $table->foreignId('salary_type_id')->constrained()->restrictOnDelete();
            $table->primary(['grade_id', 'salary_type_id']);
            $table->index('salary_type_id');
        });

        Schema::dropIfExists('pay_scale_salary_type');
        Schema::dropIfExists('pay_scales');
    }
};
