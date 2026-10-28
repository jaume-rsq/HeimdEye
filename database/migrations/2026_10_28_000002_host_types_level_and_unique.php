<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tipus d'equip amb nivell (global, curs o mòdul), com les variables i els secrets. "abast"
 * (alumne o global) volia dir quants equips genera: ara és es_unic (un sol equip per a tothom).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tipus_equip', function (Blueprint $table) {
            $table->dropUnique(['modul_id', 'nom']);
        });
        Schema::table('tipus_equip', function (Blueprint $table) {
            $table->string('nivell')->default('modul'); // App\Enums\Level
            $table->foreignId('curs_id')->nullable()->constrained('cursos')->cascadeOnDelete();
            $table->boolean('es_unic')->default(false);
            $table->unsignedBigInteger('modul_id')->nullable()->change();
        });
        DB::table('tipus_equip')->where('abast', 'global')->update(['es_unic' => true]);
        Schema::table('tipus_equip', function (Blueprint $table) {
            $table->dropColumn('abast');
            $table->unique(['nivell', 'curs_id', 'modul_id', 'nom']);
        });
    }

    public function down(): void
    {
        Schema::table('tipus_equip', function (Blueprint $table) {
            $table->dropUnique(['nivell', 'curs_id', 'modul_id', 'nom']);
            $table->string('abast')->default('alumne');
        });
        DB::table('tipus_equip')->where('es_unic', true)->update(['abast' => 'global']);
        // Els de nivell global o de curs no tenen mòdul: abans no podien existir.
        DB::table('tipus_equip')->where('nivell', '!=', 'modul')->delete();
        Schema::table('tipus_equip', function (Blueprint $table) {
            $table->dropConstrainedForeignId('curs_id');
            $table->dropColumn(['nivell', 'es_unic']);
            $table->unique(['modul_id', 'nom']);
        });
    }
};
