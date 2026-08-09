<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_shipments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('booking_id');
            $table->decimal('bl_qty_ctn', 12, 2)->nullable();
            $table->date('org_document_date')->nullable();
            $table->date('ex_factory_date')->nullable();
            $table->date('etd')->nullable();
            $table->date('eta')->nullable();
            $table->decimal('received_qty', 12, 2)->default(0);
            $table->date('received_date')->nullable();
            $table->text('remark')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_shipments');
    }
};
