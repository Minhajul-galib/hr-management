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
            try {
                $table->foreign("department_id")->references("id")->on("departments")->onDelete("set null");
            } catch (\Throwable $e) {
                // Ignore if it already exists
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table("users", function (Blueprint $table) {
            try {
                $table->dropForeign(["department_id"]);
            } catch (\Throwable $e) {
                // Ignore if it does not exist
            }
        });
    }
};
