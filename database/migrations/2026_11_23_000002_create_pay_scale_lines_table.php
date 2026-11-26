<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A type's pay scale is no longer one fixed "basic = grade x multiplier + amount" sum. It is a
 * list of calculation lines, each with a title the user chooses (Basic, Gross, ...) and its
 * own calculation (see PayScaleLine): a grade formula, a percentage of another line, a fixed
 * amount, or the total of other lines (and the connected salary types).
 *
 * The formula a type already had becomes its first line, "Basic" (the line salary types are
 * worked out on), followed by a "Gross" line: Basic plus the type's connected salary types.
 * The two old columns are then dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pay_scale_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pay_scale_id')->constrained()->cascadeOnDelete();
            // Lines are worked out top to bottom, and may only use the ones above them.
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('title', 100);
            // grade_formula | percent | fixed | total
            $table->string('kind', 20);
            $table->decimal('multiplier', 12, 2)->nullable();   // grade_formula: grade x multiplier + amount
            $table->decimal('amount', 12, 2)->nullable();       // grade_formula (the amount added) and fixed
            $table->decimal('percent', 8, 2)->nullable();       // percent: of the one line in `sources`
            $table->json('sources')->nullable();                // ids of the lines a percent / total line uses
            $table->boolean('include_salary_types')->default(false); // total: also add the connected salary types
            $table->boolean('is_base')->default(false);         // the line "% of basic" salary types are worked out on
            $table->timestamps();

            $table->index(['pay_scale_id', 'position']);
        });

        $now = now();
        foreach (DB::table('pay_scales')->whereNotNull('basic_multiplier')->get() as $scale) {
            $basic = DB::table('pay_scale_lines')->insertGetId([
                'pay_scale_id' => $scale->id,
                'position' => 0,
                'title' => 'Basic',
                'kind' => 'grade_formula',
                'multiplier' => $scale->basic_multiplier,
                'amount' => $scale->basic_addend ?? 0,
                'is_base' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('pay_scale_lines')->insert([
                'pay_scale_id' => $scale->id,
                'position' => 1,
                'title' => 'Gross',
                'kind' => 'total',
                'sources' => json_encode([$basic]),
                'include_salary_types' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        Schema::table('pay_scales', function (Blueprint $table) {
            $table->dropColumn(['basic_multiplier', 'basic_addend']);
        });
    }

    /** Puts a type's first grade formula back in its two columns; the other lines are lost. */
    public function down(): void
    {
        Schema::table('pay_scales', function (Blueprint $table) {
            $table->decimal('basic_multiplier', 12, 2)->nullable();
            $table->decimal('basic_addend', 12, 2)->nullable();
        });

        foreach (DB::table('pay_scales')->get() as $scale) {
            $line = DB::table('pay_scale_lines')
                ->where('pay_scale_id', $scale->id)->where('kind', 'grade_formula')
                ->orderBy('position')->orderBy('id')->first();

            if ($line) {
                DB::table('pay_scales')->where('id', $scale->id)->update([
                    'basic_multiplier' => $line->multiplier,
                    'basic_addend' => $line->amount ?? 0,
                ]);
            }
        }

        Schema::dropIfExists('pay_scale_lines');
    }
};
