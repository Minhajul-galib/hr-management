<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Grade Types (Staff, Worker, ...) gave way to Salary Types: the salary heads
 * paid on top of basic (house rent, medical, conveyance, ...), each with a code,
 * a calculation and a value. A grade now picks the salary types that apply to
 * it, and its gross range is worked out from them. A grade code is unique on
 * its own, since there are no grade families to number within any more.
 *
 * The old free-text salary structure on grades is replaced by that link. Grades
 * entered under the old layout would lose it, so this refuses to run while any
 * exist; Grade Types that no grade uses are simply dropped. The old permissions
 * are renamed, so roles keep their access.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'view grade types' => 'view salary types',
        'manage grade types' => 'manage salary types',
    ];

    public function up(): void
    {
        if (DB::table('grades')->exists()) {
            throw new RuntimeException(
                'Grades already exist, and their old salary structure would be lost. '.
                'Note down the grades, delete them, run this migration, then add them again under Salary Types.'
            );
        }

        Schema::create('salary_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name')->unique();
            // percent_of_basic: `value` is a percentage of the grade's basic; fixed: `value` is the amount.
            $table->string('calculation', 20)->default('percent_of_basic');
            $table->decimal('value', 12, 2)->default(0);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('grade_salary_type', function (Blueprint $table) {
            $table->foreignId('grade_id')->constrained()->cascadeOnDelete();
            // A salary type that a grade still uses cannot be deleted.
            $table->foreignId('salary_type_id')->constrained()->restrictOnDelete();
            $table->primary(['grade_id', 'salary_type_id']);
            $table->index('salary_type_id');
        });

        Schema::table('grades', function (Blueprint $table) {
            $table->dropUnique(['grade_type_id', 'code']);
            $table->dropConstrainedForeignId('grade_type_id');
            $table->dropColumn('salary_structure');
            $table->unique('code');
        });

        Schema::dropIfExists('grade_types');

        $this->renamePermissions(self::PERMISSIONS);
    }

    /** Restores the old layout's tables and permissions; the (empty) data is not brought back. */
    public function down(): void
    {
        Schema::create('grade_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('grades', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->foreignId('grade_type_id')->nullable()->after('code')->constrained('grade_types')->restrictOnDelete();
            $table->json('salary_structure')->nullable();
            $table->unique(['grade_type_id', 'code']);
        });

        Schema::dropIfExists('grade_salary_type');
        Schema::dropIfExists('salary_types');

        $this->renamePermissions(array_flip(self::PERMISSIONS));
    }

    /**
     * Rename permissions in place so the roles that hold them keep them. When the
     * new name exists already (a fresh install creates it from the master data
     * list), the roles are moved onto it and the old one is removed.
     *
     * @param  array<string, string>  $renames  old name => new name
     */
    private function renamePermissions(array $renames): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($renames as $old => $new) {
            $oldPermission = Permission::where('name', $old)->first();
            if (! $oldPermission) {
                continue;
            }

            $newPermission = Permission::where('name', $new)->first();
            if ($newPermission) {
                foreach ($oldPermission->roles as $role) {
                    $role->givePermissionTo($newPermission);
                }
                $oldPermission->delete();
            } else {
                $oldPermission->update(['name' => $new]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
