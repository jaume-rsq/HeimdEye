<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Omple l'info per a l'alumne dels checks dels blocs 5 i 6 del mòdul 9991 que ja són a
 * la base de dades. Només toca els checks que encara no en tenen: no trepitja el que el
 * professor hagi escrit des del panell. Els seeders fan servir el mateix fitxer.
 */
return new class extends Migration
{
    public function up(): void
    {
        $info = require database_path('seeders/data/check_info.php');

        foreach ($info as $blockNumber => $checks) {
            foreach ($checks as $name => $text) {
                DB::table('checks')
                    ->where('nom', $name)
                    ->where(fn ($q) => $q->whereNull('info')->orWhere('info', ''))
                    ->whereIn('tasca_id', DB::table('tasques')
                        ->join('blocs', 'blocs.id', '=', 'tasques.bloc_id')
                        ->join('moduls', 'moduls.id', '=', 'blocs.modul_id')
                        ->where('moduls.codi', '9991')
                        ->where('blocs.numero', $blockNumber)
                        ->select('tasques.id'))
                    ->update(['info' => $text]);
            }
        }
    }

    public function down(): void
    {
        // Les dades es queden: el camp ja es buida si es desfà la migració que el crea.
    }
};
