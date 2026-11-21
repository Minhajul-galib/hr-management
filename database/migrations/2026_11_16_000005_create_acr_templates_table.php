<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acr_templates', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            // Weighted criteria: list of {name, weight}; weights total 100.
            $table->json('criteria');
            // Bands over the weighted total (0-100): list of {label, min_score, max_score}.
            $table->json('rating_scale');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('acr_template_designation', function (Blueprint $table) {
            $table->foreignId('acr_template_id')->constrained()->cascadeOnDelete();
            // A designation still used by an ACR template cannot be deleted.
            $table->foreignId('designation_id')->constrained()->restrictOnDelete();
            $table->primary(['acr_template_id', 'designation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acr_template_designation');
        Schema::dropIfExists('acr_templates');
    }
};
