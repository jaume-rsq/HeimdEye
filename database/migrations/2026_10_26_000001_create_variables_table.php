<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Una sola taula de variables amb àmbit global, de curs o de mòdul.
 * Hi passen variables_globals (global) i moduls.variables_derivades (mòdul).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('variables', function (Blueprint $table) {
            $table->id();
            $table->string('abast'); // App\Enums\VariableScope
            $table->foreignId('curs_id')->nullable()->constrained('cursos')->cascadeOnDelete();
            $table->foreignId('modul_id')->nullable()->constrained('moduls')->cascadeOnDelete();
            $table->string('clau');
            $table->text('valor'); // valor o plantilla {{var}}
            $table->string('descripcio')->nullable();
            $table->timestamps();

            $table->unique(['abast', 'curs_id', 'modul_id', 'clau']);
        });

        $now = now();
        foreach (DB::table('variables_globals')->get() as $var) {
            DB::table('variables')->insert(['abast' => 'global', 'clau' => $var->clau, 'valor' => $var->valor, 'created_at' => $now, 'updated_at' => $now]);
        }
        foreach (DB::table('moduls')->whereNotNull('variables_derivades')->get(['id', 'variables_derivades']) as $module) {
            foreach (json_decode($module->variables_derivades, true) ?? [] as $key => $value) {
                DB::table('variables')->insert(['abast' => 'modul', 'modul_id' => $module->id, 'clau' => $key, 'valor' => $value, 'created_at' => $now, 'updated_at' => $now]);
            }
        }

        Schema::drop('variables_globals');
        Schema::table('moduls', fn (Blueprint $table) => $table->dropColumn('variables_derivades'));
    }

    public function down(): void
    {
        Schema::table('moduls', fn (Blueprint $table) => $table->json('variables_derivades')->nullable());
        Schema::create('variables_globals', function (Blueprint $table) {
            $table->id();
            $table->string('clau')->unique();
            $table->string('valor');
            $table->timestamps();
        });

        $now = now();
        foreach (DB::table('variables')->where('abast', 'global')->get() as $var) {
            DB::table('variables_globals')->insert(['clau' => $var->clau, 'valor' => $var->valor, 'created_at' => $now, 'updated_at' => $now]);
        }
        foreach (DB::table('variables')->where('abast', 'modul')->get()->groupBy('modul_id') as $moduleId => $vars) {
            DB::table('moduls')->where('id', $moduleId)->update(['variables_derivades' => json_encode($vars->pluck('valor', 'clau'))]);
        }

        Schema::drop('variables');
    }
};
