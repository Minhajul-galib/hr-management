<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A grade now says which salary formula it is paid by, instead of the formula following from its
 * type (Group / VBL). Formulas become a list the user names and adds to, so grades can be paid
 * differently; the grade form and the Excel import pick one by its name.
 *
 * The two formulas so far are named "Group" and "VBL", and every grade is given the one its type
 * used until now (Group - ... types get Group, VBL - ... types get VBL).
 */
return new class extends Migration
{
    /** old company key => name */
    private const NAMES = ['group' => 'Group', 'vbl' => 'VBL'];

    /** type prefix => company key */
    private const TYPES = ['group_' => 'group', 'vbl_' => 'vbl'];

    public function up(): void
    {
        Schema::table('pay_scales', function (Blueprint $table) {
            $table->string('name', 100)->nullable()->after('id');
        });

        foreach (DB::table('pay_scales')->get(['id', 'company']) as $scale) {
            DB::table('pay_scales')->where('id', $scale->id)->update(['name' => self::NAMES[$scale->company] ?? ucfirst((string) $scale->company)]);
        }

        Schema::table('grades', function (Blueprint $table) {
            // A formula that grades use cannot be deleted.
            $table->foreignId('pay_scale_id')->nullable()->after('type')->constrained()->restrictOnDelete();
        });

        foreach (self::TYPES as $prefix => $company) {
            $scaleId = DB::table('pay_scales')->where('company', $company)->value('id');
            if ($scaleId) {
                DB::table('grades')->where('type', 'like', $prefix.'%')->update(['pay_scale_id' => $scaleId]);
            }
        }

        Schema::table('pay_scales', function (Blueprint $table) {
            $table->dropUnique(['company']);
            $table->dropColumn('company');
        });
        Schema::table('pay_scales', function (Blueprint $table) {
            $table->string('name', 100)->nullable(false)->change();
            $table->unique('name');
        });
    }

    /**
     * Back to one formula per company, followed from the grade's type. Formulas other than Group
     * and VBL have no company to go back to, so they are deleted.
     */
    public function down(): void
    {
        Schema::table('grades', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pay_scale_id');
        });

        Schema::table('pay_scales', function (Blueprint $table) {
            $table->string('company', 20)->nullable();
        });

        foreach (self::NAMES as $company => $name) {
            DB::table('pay_scales')->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->update(['company' => $company]);
        }
        DB::table('pay_scales')->whereNull('company')->delete();
        $now = now();
        foreach (array_keys(self::NAMES) as $company) {
            if (! DB::table('pay_scales')->where('company', $company)->exists()) {
                DB::table('pay_scales')->insert(['company' => $company, 'name' => $company, 'created_at' => $now, 'updated_at' => $now]);
            }
        }

        Schema::table('pay_scales', function (Blueprint $table) {
            $table->dropUnique(['name']);
            $table->dropColumn('name');
        });
        Schema::table('pay_scales', function (Blueprint $table) {
            $table->string('company', 20)->nullable(false)->change();
            $table->unique('company');
        });
    }
};
