<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grades', function (Blueprint $table) {
            // Interim fixed column; replaced by the grade_types master in 000009.
            // Rows that already exist are filed under 'staff'.
            $table->string('grade_type', 20)->default('staff')->after('code');

            // Each family numbers its own grades, so a code only has to be unique within its type.
            $table->dropUnique(['code']);
            $table->unique(['grade_type', 'code']);
            $table->index('grade_type');
        });
    }

    public function down(): void
    {
        Schema::table('grades', function (Blueprint $table) {
            $table->dropIndex(['grade_type']);
            $table->dropUnique(['grade_type', 'code']);
            $table->unique('code');
            $table->dropColumn('grade_type');
        });
    }
};
