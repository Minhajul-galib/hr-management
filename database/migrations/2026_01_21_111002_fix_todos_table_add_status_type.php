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
            // Check if columns exist before adding them
            if (!Schema::hasColumn('todos', 'status')) {
                $table->enum('status', ['Assigned', 'Processing', 'Testing', 'Submitted', 'Approved'])->default('Assigned');
            }
            
            if (!Schema::hasColumn('todos', 'type')) {
                $table->enum('type', ['public', 'personal', 'confidential'])->default('personal');
            }
            
            // Drop completed column if it exists
            if (Schema::hasColumn('todos', 'completed')) {
                $table->dropColumn('completed');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('todos', function (Blueprint $table) {
            if (Schema::hasColumn('todos', 'status')) {
                $table->dropColumn('status');
            }
            
            if (Schema::hasColumn('todos', 'type')) {
                $table->dropColumn('type');
            }
            
            if (!Schema::hasColumn('todos', 'completed')) {
                $table->boolean('completed')->default(false);
            }
        });
    }
};
