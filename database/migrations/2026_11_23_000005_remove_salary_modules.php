<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Salary Module (salary types) is no longer a master: a company's salary formulas name their own
 * components (Basic, House Rent, Conveyance, Gross, ...), so a separate list of heads added
 * nothing. Its screen and permissions go, and formula components are no longer linked to it.
 *
 * The `salary_types` table itself is left in place, unused, so no saved row is lost on deploy;
 * drop it in a later migration once nobody needs the old rows.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'view salary types', 'create salary types', 'edit salary types', 'delete salary types',
        'import salary types', 'export salary types', 'manage salary types',
    ];

    public function up(): void
    {
        Schema::table('pay_scale_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('salary_type_id');
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::whereIn('name', self::PERMISSIONS)->delete(); // and every role's grant of them
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Brings the link and the permissions back. Which roles held the permissions is not known
     * any more, so they go to the two roles that had them by default: Super System Admin and Editor.
     */
    public function down(): void
    {
        Schema::table('pay_scale_lines', function (Blueprint $table) {
            $table->foreignId('salary_type_id')->nullable()->after('formula')->constrained()->restrictOnDelete();
        });

        $modules = DB::table('salary_types')->get(['id', 'name'])->keyBy(fn ($m) => mb_strtolower(trim($m->name)));
        foreach (DB::table('pay_scale_lines')->get(['id', 'title']) as $line) {
            if ($module = $modules->get(mb_strtolower(trim($line->title)))) {
                DB::table('pay_scale_lines')->where('id', $line->id)->update(['salary_type_id' => $module->id]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (self::PERMISSIONS as $name) {
            Permission::findOrCreate($name);
        }
        foreach (['Super System Admin', 'Editor'] as $role) {
            Role::where('name', $role)->first()?->givePermissionTo(self::PERMISSIONS);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
