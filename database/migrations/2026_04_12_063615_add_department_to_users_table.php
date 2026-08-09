<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table("users", function (Blueprint $table) {
            if (!Schema::hasColumn("users", "department_id")) {
                $table->unsignedBigInteger("department_id")->nullable()->after("unit_id");
                $table->index("department_id");
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table("users", function (Blueprint $table) {
            if (Schema::hasColumn("users", "department_id")) {
                try { $table->dropForeign(["department_id"]); } catch (\Throwable $e) {}
                $table->dropIndex(["department_id"]);
                $table->dropColumn("department_id");
            }
        });
    }
};
