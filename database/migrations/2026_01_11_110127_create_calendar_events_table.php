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
        Schema::create('calendar_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('title');
            $table->text('description')->nullable();
            $table->datetime('start');
            $table->datetime('end')->nullable();
            $table->boolean('all_day')->default(false);
            $table->boolean('is_holiday')->default(false); // সরকারি ছুটির জন্য
            $table->string('color')->default('#3788d8'); // ইভেন্ট কালার
            $table->datetime('reminder_at')->nullable(); // অ্যালার্ম সময়
            $table->enum('status', ['pending', 'completed', 'cancelled'])->default('pending');
            $table->json('recurrence')->nullable(); // রিপিট ইভেন্টের জন্য
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('calendar_events');
    }
};
