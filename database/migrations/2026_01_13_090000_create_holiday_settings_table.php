<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holiday_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('all_fridays')->default(false);
            $table->json('weekday_off')->nullable(); // e.g., [0,5] for Sunday & Friday
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holiday_settings');
    }
};
