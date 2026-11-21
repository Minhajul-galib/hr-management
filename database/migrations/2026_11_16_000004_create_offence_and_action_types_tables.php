<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('action_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('offence_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->string('severity', 20)->index(); // minor | major | gross
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Which disciplinary actions may be taken for which offence.
        Schema::create('action_type_offence_type', function (Blueprint $table) {
            $table->foreignId('offence_type_id')->constrained()->cascadeOnDelete();
            // An action type that is still permitted for an offence cannot be deleted.
            $table->foreignId('action_type_id')->constrained()->restrictOnDelete();
            $table->primary(['offence_type_id', 'action_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('action_type_offence_type');
        Schema::dropIfExists('offence_types');
        Schema::dropIfExists('action_types');
    }
};
