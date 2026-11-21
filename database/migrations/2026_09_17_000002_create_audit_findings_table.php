<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_id')->constrained('audits')->cascadeOnDelete();
            $table->string('category', 100);
            $table->string('rating', 30);
            $table->text('finding');

            // Corrective action plan (CAP); status is null for 'compliant' findings, which need no CAP
            $table->text('corrective_action')->nullable();
            $table->string('responsible_person')->nullable();
            $table->date('target_date')->nullable();
            $table->string('status', 20)->nullable(); // open | closed
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();

            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['audit_id', 'sort_order']);
            $table->index(['status', 'target_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_findings');
    }
};
