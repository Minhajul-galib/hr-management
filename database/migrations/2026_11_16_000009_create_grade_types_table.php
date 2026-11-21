<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Grade types become data the HR team maintains (like leave types), replacing
 * the fixed `grades.grade_type` text column added in 000008.
 */
return new class extends Migration
{
    private const PERMISSIONS = ['view grade types', 'manage grade types'];

    public function up(): void
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
            $table->foreignId('grade_type_id')->nullable()->after('code')->constrained('grade_types')->restrictOnDelete();
        });

        // Keep any grades that already exist: turn each distinct old type into a grade type.
        foreach (DB::table('grades')->distinct()->pluck('grade_type') as $old) {
            $id = DB::table('grade_types')->insertGetId([
                'code' => strtoupper($old),
                'name' => ucfirst($old),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('grades')->where('grade_type', $old)->update(['grade_type_id' => $id]);
        }

        Schema::table('grades', function (Blueprint $table) {
            $table->dropUnique(['grade_type', 'code']);
            $table->dropIndex(['grade_type']);
            $table->dropColumn('grade_type');
        });

        Schema::table('grades', function (Blueprint $table) {
            $table->unsignedBigInteger('grade_type_id')->nullable(false)->change();
            // Each type numbers its own grades, so a code is unique within its type.
            $table->unique(['grade_type_id', 'code']);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (self::PERMISSIONS as $name) {
            Permission::findOrCreate($name);
        }
        Role::where('name', 'Super System Admin')->first()?->givePermissionTo(self::PERMISSIONS);
        Role::where('name', 'HR Manager')->first()?->givePermissionTo(self::PERMISSIONS);
        Role::where('name', 'HR Executive')->first()?->givePermissionTo('view grade types');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** Grade types are dropped; every grade falls back to the 'staff' column default. */
    public function down(): void
    {
        Schema::table('grades', function (Blueprint $table) {
            $table->dropUnique(['grade_type_id', 'code']);
            $table->dropConstrainedForeignId('grade_type_id');
            $table->string('grade_type', 20)->default('staff')->after('code');
        });

        Schema::table('grades', function (Blueprint $table) {
            $table->unique(['grade_type', 'code']);
            $table->index('grade_type');
        });

        Schema::dropIfExists('grade_types');

        Permission::whereIn('name', self::PERMISSIONS)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
