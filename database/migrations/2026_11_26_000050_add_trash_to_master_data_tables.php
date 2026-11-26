<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Master-data pages get a Trash, as Units already have: deleting a record (or ticking several
 * and deleting them together) moves it to the Trash, from where it can be restored or deleted
 * for good. A trashed record keeps its code, so a code is never handed to a different record
 * by accident, and records linked to it by code stay meaningful.
 *
 * Only adds a nullable column; nothing existing is touched.
 */
return new class extends Migration
{
    private const TABLES = ['departments', 'designations', 'grades', 'leave_types', 'offence_types', 'acr_templates', 'document_types'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, fn (Blueprint $t) => $t->softDeletes());
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasColumn($table, 'deleted_at')) {
                // Records still in the Trash would come back as live ones, so they go for good.
                \Illuminate\Support\Facades\DB::table($table)->whereNotNull('deleted_at')->delete();
                Schema::table($table, fn (Blueprint $t) => $t->dropSoftDeletes());
            }
        }
    }
};
