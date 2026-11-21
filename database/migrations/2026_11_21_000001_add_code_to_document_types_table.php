<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every master is identified by a unique code, so later modules (employee
 * documents, imports) can refer to a document type by code instead of by its
 * name. Existing rows get a code made from their name, e.g. "National ID" →
 * NATIONAL_ID, with _2, _3, … when two names give the same code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_types', function (Blueprint $table) {
            $table->string('code', 50)->nullable()->unique()->after('id');
        });

        $used = [];
        foreach (DB::table('document_types')->orderBy('id')->get(['id', 'name']) as $row) {
            $base = trim(preg_replace('/[^A-Z0-9]+/', '_', strtoupper((string) $row->name)), '_');
            $base = substr($base !== '' ? $base : 'DOC', 0, 45);

            $code = $base;
            for ($n = 2; isset($used[$code]); $n++) {
                $code = "{$base}_{$n}";
            }
            $used[$code] = true;

            DB::table('document_types')->where('id', $row->id)->update(['code' => $code]);
        }

        Schema::table('document_types', function (Blueprint $table) {
            $table->string('code', 50)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('document_types', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn('code');
        });
    }
};
