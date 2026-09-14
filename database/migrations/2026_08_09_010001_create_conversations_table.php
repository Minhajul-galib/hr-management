<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->string('type', 10)->default('direct'); // direct | group
            $table->string('name')->nullable();            // groups only
            $table->string('avatar_path')->nullable();     // groups only
            // For 1:1 threads: "<lowerUserId>:<higherUserId>". Keeps a pair from
            // ever ending up with two parallel threads, even under a race.
            $table->string('direct_key', 40)->nullable()->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['type', 'last_message_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
