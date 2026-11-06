<?php

use App\Support\Library;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Curs «Biblioteca»: el del mòdul 0000 de checks i accions genèrics. No surt als selectors
        // de curs ni és mai l'actual.
        Schema::table('cursos', function (Blueprint $table) {
            $table->boolean('es_biblioteca')->default(false)->after('es_actual');
        });

        // A producció ja hi ha cicles; en una base de dades buida el crea el seeder.
        Library::ensure();
    }

    public function down(): void
    {
        Schema::table('cursos', function (Blueprint $table) {
            $table->dropColumn('es_biblioteca');
        });
    }
};
