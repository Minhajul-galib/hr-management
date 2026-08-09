<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_teams', function (Blueprint $table) {
            $table->string('code')->nullable()->after('name');
            $table->string('ep_code')->nullable()->after('code');
            $table->string('head_name')->nullable()->after('ep_code');
            $table->string('head_designation')->nullable()->after('head_name');
        });
    }

    public function down(): void
    {
        Schema::table('merchant_teams', function (Blueprint $table) {
            $table->dropColumn(['code', 'ep_code', 'head_name', 'head_designation']);
        });
    }
};
