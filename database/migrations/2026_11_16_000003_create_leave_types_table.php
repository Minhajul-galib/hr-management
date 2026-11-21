<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->boolean('is_paid')->default(true);
            // Days per year.
            $table->decimal('entitlement_days', 6, 1)->default(0);
            // yearly = full entitlement credited at the start of the year,
            // monthly = 1/12 credited each month, none = no periodic credit.
            $table->string('accrual', 20)->default('yearly');
            $table->boolean('carry_forward')->default(false);
            // Null while carry_forward is on means "no cap".
            $table->decimal('carry_forward_limit_days', 6, 1)->nullable();
            $table->boolean('attachment_required')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_types');
    }
};
