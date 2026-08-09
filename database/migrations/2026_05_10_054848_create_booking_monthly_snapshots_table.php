<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_monthly_snapshots', function (Blueprint $table) {
            $table->id();
            $table->smallInteger('fiscal_year');
            $table->tinyInteger('month');
            $table->unsignedBigInteger('supplier_id');
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->unsignedBigInteger('yarn_count_id')->nullable();
            $table->unsignedBigInteger('fiber_type_id')->nullable();
            $table->decimal('total_order_kg', 12, 2)->default(0);
            $table->decimal('total_received_kg', 12, 2)->default(0);
            $table->decimal('pending_kg', 12, 2)->default(0);
            $table->decimal('balance_kg', 12, 2)->default(0);
            $table->timestamp('captured_at')->useCurrent();
            $table->timestamps();

            $table->index(['fiscal_year', 'month']);
            $table->index('supplier_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_monthly_snapshots');
    }
};
