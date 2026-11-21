<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_mandatory')->default(false);
            $table->string('module', 30)->index();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['module', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_types');
    }
};
