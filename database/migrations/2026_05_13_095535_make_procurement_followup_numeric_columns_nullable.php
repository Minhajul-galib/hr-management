<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('procurement_followups', function (Blueprint $table) {
            $table->decimal('order_qty_kg', 14, 3)->nullable()->default(null)->change();
            $table->decimal('received_qty', 14, 3)->nullable()->default(null)->change();
            $table->decimal('balance_qty', 14, 3)->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('procurement_followups', function (Blueprint $table) {
            $table->decimal('order_qty_kg', 14, 3)->default(0)->change();
            $table->decimal('received_qty', 14, 3)->default(0)->change();
            $table->decimal('balance_qty', 14, 3)->default(0)->change();
        });
    }
};
