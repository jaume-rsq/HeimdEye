<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Una incidència pot ser d'una tasca (dins del bloc): un bloc pot tenir incidències diferents per
 * tasca. Les que ja hi són prenen la tasca dels checks de resolució de les opcions, si és una sola.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidencies', function (Blueprint $table) {
            $table->foreignId('tasca_id')->nullable()->after('bloc_id')->constrained('tasques')->nullOnDelete();
        });

        $tasks = DB::table('opcions_incidencia')
            ->join('checks', 'checks.id', '=', 'opcions_incidencia.check_id')
            ->select('opcions_incidencia.incidencia_id', 'checks.tasca_id')
            ->distinct()->get()
            ->groupBy('incidencia_id');

        foreach ($tasks as $incident => $rows) {
            if ($rows->count() === 1) {
                DB::table('incidencies')->where('id', $incident)->update(['tasca_id' => $rows->first()->tasca_id]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('incidencies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tasca_id');
        });
    }
};
