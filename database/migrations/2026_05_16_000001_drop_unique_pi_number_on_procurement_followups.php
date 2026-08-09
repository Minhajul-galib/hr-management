<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Use raw SQL so we can use IF EXISTS — the constraint may already be absent
        // on databases that were created without the unique index.
        \DB::statement('ALTER TABLE procurement_followups DROP CONSTRAINT IF EXISTS procurement_followups_pi_number_unique');
    }

    public function down(): void
    {
        Schema::table('procurement_followups', function (Blueprint $table) {
            $table->unique('pi_number');
        });
    }
};
