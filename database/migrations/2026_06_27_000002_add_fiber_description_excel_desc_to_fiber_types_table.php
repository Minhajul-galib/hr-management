<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiber_types', function (Blueprint $table) {
            $table->string('fiber_description')->nullable()->after('name');
            $table->string('excel_description')->nullable()->after('fiber_description');
        });
    }

    public function down(): void
    {
        Schema::table('fiber_types', function (Blueprint $table) {
            $table->dropColumn(['fiber_description', 'excel_description']);
        });
    }
};
