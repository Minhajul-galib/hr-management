<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A designation list is not one ladder here: Merchandisers, office staff, machine operators and
 * so on each have their own, and each ladder is numbered (serial) from 1 on its own. So every
 * designation now belongs to a category (Designation Categories, added, renamed and deleted by
 * the user on the Designations page).
 *
 * Designations that already exist all go into a "General" category, which the user can rename
 * or move them out of. A category that designations still belong to cannot be deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('designation_categories')) {
            Schema::create('designation_categories', function (Blueprint $table) {
                $table->id();
                $table->string('name', 100)->unique();
                $table->timestamps();
            });
        }

        if (Schema::hasColumn('designations', 'designation_category_id')) {
            return;
        }

        $now = now();
        $general = DB::table('designation_categories')->where('name', 'General')->value('id')
            ?? DB::table('designation_categories')->insertGetId(['name' => 'General', 'created_at' => $now, 'updated_at' => $now]);

        // Added empty, filled for the designations already saved, and only then made required.
        Schema::table('designations', function (Blueprint $table) {
            $table->unsignedBigInteger('designation_category_id')->nullable()->after('id');
        });

        DB::table('designations')->update(['designation_category_id' => $general]);

        Schema::table('designations', function (Blueprint $table) {
            $table->unsignedBigInteger('designation_category_id')->nullable(false)->change();
            $table->foreign('designation_category_id')->references('id')->on('designation_categories')->restrictOnDelete();
            // The list is read by category, then by serial within it.
            $table->index(['designation_category_id', 'serial']);
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('designations', 'designation_category_id')) {
            Schema::table('designations', function (Blueprint $table) {
                $table->dropForeign(['designation_category_id']);
                $table->dropIndex(['designation_category_id', 'serial']);
                $table->dropColumn('designation_category_id');
            });
        }

        Schema::dropIfExists('designation_categories');
    }
};
