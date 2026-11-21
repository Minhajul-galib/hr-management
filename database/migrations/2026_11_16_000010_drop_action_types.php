<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Disciplinary Actions (and the "permitted actions" link on offence types) were
 * dropped from the master data for now. Offence Types stand on their own.
 */
return new class extends Migration
{
    private const PERMISSIONS = ['view action types', 'manage action types'];

    public function up(): void
    {
        Schema::dropIfExists('action_type_offence_type');
        Schema::dropIfExists('action_types');

        Permission::whereIn('name', self::PERMISSIONS)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** Brings the (empty) tables and permissions back; data is not restored. */
    public function down(): void
    {
        Schema::create('action_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('action_type_offence_type', function (Blueprint $table) {
            $table->foreignId('offence_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('action_type_id')->constrained()->restrictOnDelete();
            $table->primary(['offence_type_id', 'action_type_id']);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (self::PERMISSIONS as $name) {
            Permission::findOrCreate($name);
        }
        Role::where('name', 'Super System Admin')->first()?->givePermissionTo(self::PERMISSIONS);
        Role::where('name', 'HR Manager')->first()?->givePermissionTo(self::PERMISSIONS);
        Role::where('name', 'HR Executive')->first()?->givePermissionTo('view action types');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
