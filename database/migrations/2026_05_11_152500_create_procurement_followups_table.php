<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procurement_followups', function (Blueprint $table) {
            $table->id();
            $table->string('supplier', 255)->nullable();
            $table->string('merchant_team', 255)->nullable();
            $table->string('yarn_count', 100)->nullable();
            $table->string('booking_type', 10)->nullable();
            $table->string('fiber', 255)->nullable();
            $table->decimal('order_qty_kg', 14, 3)->default(0);
            $table->string('pi_number', 100)->unique();
            $table->date('pi_received_date')->nullable();
            $table->string('lc_number', 100)->nullable();
            $table->date('lc_issue_date')->nullable();
            $table->string('lc_factory', 50)->nullable();
            $table->string('commercial_invoice', 100)->nullable();
            $table->decimal('bl_qty_ctn', 14, 3)->nullable();
            $table->date('org_document_date')->nullable();
            $table->date('ex_factory_date')->nullable();
            $table->date('etd')->nullable();
            $table->date('eta')->nullable();
            $table->decimal('received_qty', 14, 3)->default(0);
            $table->date('received_date')->nullable();
            $table->decimal('balance_qty', 14, 3)->default(0);
            $table->text('remark')->nullable();
            $table->smallInteger('fiscal_year')->nullable()->index();
            $table->string('import_batch', 100)->nullable()->index();
            $table->timestamps();

            $table->index('supplier');
            $table->index('merchant_team');
            $table->index('yarn_count');
            $table->index('fiber');
            $table->index('eta');
            $table->index('pi_received_date');
            $table->index('received_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_followups');
    }
};
