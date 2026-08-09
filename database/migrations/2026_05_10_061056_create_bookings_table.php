<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->smallInteger('fiscal_year');
            $table->foreignId('supplier_id')->constrained('yarn_suppliers')->restrictOnDelete();
            $table->foreignId('merchant_team_id')->nullable()->constrained('merchant_teams')->nullOnDelete();
            $table->foreignId('yarn_count_id')->nullable()->constrained('yarn_counts')->nullOnDelete();
            $table->foreignId('fiber_type_id')->nullable()->constrained('fiber_types')->nullOnDelete();
            $table->foreignId('booking_type_id')->nullable()->constrained('booking_types')->nullOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->decimal('order_qty_kg', 12, 2);
            $table->string('pi_number')->nullable();
            $table->date('pi_received_date')->nullable();
            $table->string('status', 50)->default('pending');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('fiscal_year');
            $table->index('supplier_id');
            $table->index('merchant_team_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
