<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A grade is now just a code and its salary types: it no longer has a name or a basic
 * salary range (and so no gross range either).
 *
 * The three columns are made optional rather than dropped, so any grade already saved
 * keeps what it had and nothing is lost on deploy. The app no longer reads or writes
 * them; drop them in a later migration once nobody needs the old values.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grades', function (Blueprint $table) {
            $table->string('name')->nullable()->change();
            $table->decimal('basic_min', 12, 2)->nullable()->change();
            $table->decimal('basic_max', 12, 2)->nullable()->change();
        });
    }

    /** Grades saved since have none of these; give them the values the old layout required. */
    public function down(): void
    {
        DB::table('grades')->whereNull('name')->update(['name' => DB::raw('code')]);
        DB::table('grades')->whereNull('basic_min')->update(['basic_min' => 0]);
        DB::table('grades')->whereNull('basic_max')->update(['basic_max' => 0]);

        Schema::table('grades', function (Blueprint $table) {
            $table->string('name')->nullable(false)->change();
            $table->decimal('basic_min', 12, 2)->nullable(false)->change();
            $table->decimal('basic_max', 12, 2)->nullable(false)->change();
        });
    }
};
