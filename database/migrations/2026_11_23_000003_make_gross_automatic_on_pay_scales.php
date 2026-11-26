<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gross is no longer a calculation line the user builds. It is worked out for every type: all
 * of the type's calculations (Basic, Medical, ...) plus its connected salary modules. So the
 * "total" kind is gone, along with the "Gross" line the previous migration added for
 * Group - Male, and a line that is a percentage of another now points at it with a single
 * `source_line_id` instead of a list of ids.
 *
 * A percentage line that pointed at a deleted total (or at a line that went with it) is
 * deleted too, since it has nothing to be a percentage of; and a type that is left with lines
 * but no base for "% of basic" salary modules gets its first line as the base.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pay_scale_lines', function (Blueprint $table) {
            $table->foreignId('source_line_id')->nullable()->after('percent')->constrained('pay_scale_lines')->nullOnDelete();
        });

        foreach (DB::table('pay_scale_lines')->where('kind', 'percent')->get() as $line) {
            $source = json_decode($line->sources ?? 'null', true)[0] ?? null;
            DB::table('pay_scale_lines')->where('id', $line->id)->update(['source_line_id' => $source]);
        }

        DB::table('pay_scale_lines')->where('kind', 'total')->delete();

        // Deleting a line empties the source of the lines that were a percentage of it; those go too.
        do {
            $orphans = DB::table('pay_scale_lines')->where('kind', 'percent')->whereNull('source_line_id')->delete();
        } while ($orphans > 0);

        foreach (DB::table('pay_scales')->pluck('id') as $scaleId) {
            $lines = DB::table('pay_scale_lines')->where('pay_scale_id', $scaleId);
            if ((clone $lines)->exists() && ! (clone $lines)->where('is_base', true)->exists()) {
                $first = (clone $lines)->orderBy('position')->orderBy('id')->first();
                DB::table('pay_scale_lines')->where('id', $first->id)->update(['is_base' => true]);
            }
        }

        Schema::table('pay_scale_lines', function (Blueprint $table) {
            $table->dropColumn(['sources', 'include_salary_types']);
        });
    }

    /** Brings the columns back; the deleted total lines (Gross) are not restored. */
    public function down(): void
    {
        Schema::table('pay_scale_lines', function (Blueprint $table) {
            $table->json('sources')->nullable();
            $table->boolean('include_salary_types')->default(false);
        });

        foreach (DB::table('pay_scale_lines')->whereNotNull('source_line_id')->get() as $line) {
            DB::table('pay_scale_lines')->where('id', $line->id)->update(['sources' => json_encode([$line->source_line_id])]);
        }

        Schema::table('pay_scale_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_line_id');
        });
    }
};
