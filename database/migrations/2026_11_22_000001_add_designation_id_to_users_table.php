<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The designation an applicant picks when they register. Nullable: existing
     * users have none, and a designation can be removed from the master list.
     */
    public function up(): void
    {
        if (Schema::hasColumn('users', 'designation_id')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('designation_id')->nullable()->constrained('designations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'designation_id')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('designation_id');
        });
    }
};
