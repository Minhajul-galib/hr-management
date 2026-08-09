<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('yarn_suppliers', function (Blueprint $table) {
            $table->string('alphabet', 5)->nullable()->after('name');
            $table->string('excel_input')->nullable()->after('country');
            $table->string('contact')->nullable()->after('excel_input');
        });
    }

    public function down(): void
    {
        Schema::table('yarn_suppliers', function (Blueprint $table) {
            $table->dropColumn(['alphabet', 'excel_input', 'contact']);
        });
    }
};
