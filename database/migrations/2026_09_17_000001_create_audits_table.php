<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audits', function (Blueprint $table) {
            $table->id();
            $table->string('reference_no')->nullable()->unique();
            $table->string('status', 20)->default('draft'); // draft | completed

            // Facility information (name/address are snapshotted: the report must not change if the unit is edited later)
            $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->string('facility_name');
            $table->string('facility_address', 500)->nullable();
            $table->unsignedInteger('number_of_employees')->nullable();

            // Assessment information
            $table->date('assessment_date');
            $table->string('assessment_standard', 50);
            $table->string('assessment_partner')->nullable();
            $table->string('audit_type', 20); // initial | follow_up
            $table->json('auditors');
            $table->json('factory_representatives')->nullable();

            // Short description
            $table->string('established_year', 50)->nullable();
            $table->string('total_area', 100)->nullable();
            $table->string('warehouse_area', 100)->nullable();
            $table->string('production_area', 100)->nullable();
            $table->string('office_area', 100)->nullable();
            $table->string('recent_buyer')->nullable();
            $table->string('monthly_capacity', 100)->nullable();
            $table->unsignedInteger('total_lines')->nullable();
            $table->unsignedInteger('total_machines')->nullable();

            // Template checklists, stored as row arrays (see App\Support\AuditTemplate)
            $table->json('manpower')->nullable();
            $table->string('building_storeys', 50)->nullable();
            $table->json('floor_layout')->nullable();
            $table->json('legal_documents')->nullable();
            $table->json('fire_equipment')->nullable();

            // Result
            $table->string('overall_result', 30)->nullable();
            $table->text('result_comments')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'assessment_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audits');
    }
};
