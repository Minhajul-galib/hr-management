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
        Schema::table('todos', function (Blueprint $table) {
            // Drop the old completed column if it exists
            if (Schema::hasColumn('todos', 'completed')) {
                $table->dropColumn('completed');
            }
            
            // Add status column with the 5 required statuses
            $table->enum('status', ['Assigned', 'Processing', 'Testing', 'Submitted', 'Approved'])->default('Assigned');
            
            // Add type column
            $table->enum('type', ['public', 'personal', 'confidential'])->default('personal');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('todos', function (Blueprint $table) {
            $table->dropColumn(['status', 'type']);
            $table->boolean('completed')->default(false);
        });
    }
};
