<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El que veu l'alumne d'una incidència: un símptoma per opció, l'interval entre verificacions de
 * cada incidència i el tipus de check «incidencia» (no puntua ni surt als recomptes). Els checks
 * que ja es feien servir només per a incidències (en una opció, desactivats a mà i amb 0 punts)
 * passen a aquest tipus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opcions_incidencia', function (Blueprint $table) {
            $table->text('simptoma')->nullable(); // buit: el de la incidència
        });
        Schema::table('incidencies', function (Blueprint $table) {
            $table->unsignedSmallInteger('minuts_verificacio')->nullable(); // buit: 10
        });

        DB::table('checks')
            ->whereIn('id', DB::table('opcions_incidencia')->whereNotNull('check_id')->select('check_id'))
            ->where('es_actiu', false)
            ->where('punts', 0)
            ->update([
                'tipus' => 'incidencia', 'bonus_puntualitat_pct' => 0,
                'actiu_des' => null, 'actiu_fins' => null, 'data_limit' => null,
            ]);
    }

    public function down(): void
    {
        // El codi anterior no coneix aquest tipus: tornen a ser opcionals (desactivats i sense punts).
        DB::table('checks')->where('tipus', 'incidencia')->update(['tipus' => 'opcional', 'es_actiu' => false]);

        Schema::table('incidencies', function (Blueprint $table) {
            $table->dropColumn('minuts_verificacio');
        });
        Schema::table('opcions_incidencia', function (Blueprint $table) {
            $table->dropColumn('simptoma');
        });
    }
};
