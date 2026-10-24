<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tot el que decideix què s'executa i com passa a la versió (codi, paràmetres, temps màxim
 * i, a les accions, els checks de verificació). L'executor es queda al check o a l'acció.
 * La credencial i l'equip de destinació ja no són camps: es declaren al codi amb les
 * capçaleres "# credencial:" i "# hosts:".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('versions_check', function (Blueprint $table) {
            $table->json('parametres')->nullable()->after('codi');
            $table->unsignedSmallInteger('temps_max_seg')->default(15)->after('parametres');
        });
        Schema::table('versions_accio', function (Blueprint $table) {
            $table->json('parametres')->nullable()->after('codi');
            $table->json('checks_verificacio')->nullable()->after('parametres');
            $table->unsignedSmallInteger('temps_max_seg')->default(300)->after('checks_verificacio');
        });

        foreach (DB::table('checks')->get() as $check) {
            DB::table('versions_check')->where('check_id', $check->id)->update(['temps_max_seg' => $check->temps_max_seg]);
        }
        foreach (DB::table('accions')->get() as $action) {
            DB::table('versions_accio')->where('accio_id', $action->id)->update([
                'parametres' => $action->parametres,
                'checks_verificacio' => $action->checks_verificacio,
                'temps_max_seg' => $action->temps_max_seg,
            ]);
        }
        // configuracio.host_type passa a ser la capçalera "# hosts:" del codi.
        foreach (['versions_check', 'versions_accio'] as $table) {
            foreach (DB::table($table)->whereNotNull('configuracio')->get() as $version) {
                $type = json_decode($version->configuracio, true)['host_type'] ?? null;
                if ($type !== null) {
                    DB::table($table)->where('id', $version->id)->update(['codi' => "# hosts: {$type}\n{$version->codi}"]);
                }
            }
        }

        Schema::table('versions_check', fn (Blueprint $table) => $table->dropColumn('configuracio'));
        Schema::table('versions_accio', fn (Blueprint $table) => $table->dropColumn('configuracio'));
        Schema::table('checks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('credencial_id');
            $table->dropColumn('temps_max_seg');
        });
        Schema::table('accions', fn (Blueprint $table) => $table->dropColumn(['parametres', 'checks_verificacio', 'temps_max_seg']));
    }

    public function down(): void
    {
        Schema::table('checks', function (Blueprint $table) {
            $table->unsignedSmallInteger('temps_max_seg')->default(15);
            $table->foreignId('credencial_id')->nullable()->constrained('credencials')->nullOnDelete();
        });
        Schema::table('accions', function (Blueprint $table) {
            $table->json('parametres')->nullable();
            $table->json('checks_verificacio')->nullable();
            $table->unsignedSmallInteger('temps_max_seg')->default(300);
        });
        Schema::table('versions_check', fn (Blueprint $table) => $table->json('configuracio')->nullable());
        Schema::table('versions_accio', fn (Blueprint $table) => $table->json('configuracio')->nullable());

        // Es recupera el valor de la versió vigent; la capçalera "# hosts:" es queda al codi.
        foreach (DB::table('checks')->get() as $check) {
            $version = DB::table('versions_check')->where('check_id', $check->id)->orderByDesc('versio')->first();
            if ($version) {
                DB::table('checks')->where('id', $check->id)->update(['temps_max_seg' => $version->temps_max_seg]);
            }
        }
        foreach (DB::table('accions')->get() as $action) {
            $version = DB::table('versions_accio')->where('accio_id', $action->id)->orderByDesc('versio')->first();
            if ($version) {
                DB::table('accions')->where('id', $action->id)->update([
                    'parametres' => $version->parametres,
                    'checks_verificacio' => $version->checks_verificacio,
                    'temps_max_seg' => $version->temps_max_seg,
                ]);
            }
        }

        Schema::table('versions_check', fn (Blueprint $table) => $table->dropColumn(['parametres', 'temps_max_seg']));
        Schema::table('versions_accio', fn (Blueprint $table) => $table->dropColumn(['parametres', 'checks_verificacio', 'temps_max_seg']));
    }
};
