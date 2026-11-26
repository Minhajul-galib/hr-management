<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A grade has a type: Group - Male, Group - Female or 65 Ages, VBL - Male or
 * VBL - Female or 65 Ages (see Grade). The column is optional in the database so a grade
 * saved before this keeps working; the form and the import ask for one from now on, and an
 * existing grade is given its type the next time it is saved or imported.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grades', function (Blueprint $table) {
            $table->string('type', 30)->nullable()->after('code');
        });
    }

    public function down(): void
    {
        Schema::table('grades', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
