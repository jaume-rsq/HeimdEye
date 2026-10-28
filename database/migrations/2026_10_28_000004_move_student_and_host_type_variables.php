<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Les variables pròpies d'un alumne (alumnes.variables) i les d'un tipus d'equip
 * (tipus_equip.variables_connexio) passen a la taula de variables, amb nivell "alumne" o "equip".
 * Els valors es desen com a text: true, false i els enters es tornen a convertir en generar
 * l'inventari Ansible, com en un group_vars.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('variables', function (Blueprint $table) {
            $table->foreignId('alumne_id')->nullable()->constrained('alumnes')->cascadeOnDelete();
            $table->foreignId('tipus_equip_id')->nullable()->constrained('tipus_equip')->cascadeOnDelete();
            $table->unique(['alumne_id', 'clau']);
            $table->unique(['tipus_equip_id', 'clau']);
        });

        $now = now();
        foreach (DB::table('alumnes')->whereNotNull('variables')->get(['id', 'variables']) as $student) {
            foreach (json_decode($student->variables, true) ?? [] as $key => $value) {
                DB::table('variables')->insert(['nivell' => 'alumne', 'alumne_id' => $student->id, 'clau' => $key, 'valor' => self::text($value), 'created_at' => $now, 'updated_at' => $now]);
            }
        }
        foreach (DB::table('tipus_equip')->whereNotNull('variables_connexio')->get(['id', 'variables_connexio']) as $type) {
            foreach (json_decode($type->variables_connexio, true) ?? [] as $key => $value) {
                DB::table('variables')->insert(['nivell' => 'equip', 'tipus_equip_id' => $type->id, 'clau' => $key, 'valor' => self::text($value), 'created_at' => $now, 'updated_at' => $now]);
            }
        }

        Schema::table('alumnes', fn (Blueprint $table) => $table->dropColumn('variables'));
        Schema::table('tipus_equip', fn (Blueprint $table) => $table->dropColumn('variables_connexio'));
    }

    public function down(): void
    {
        Schema::table('alumnes', fn (Blueprint $table) => $table->json('variables')->nullable());
        Schema::table('tipus_equip', fn (Blueprint $table) => $table->json('variables_connexio')->nullable());

        foreach (DB::table('variables')->where('nivell', 'alumne')->get()->groupBy('alumne_id') as $id => $vars) {
            DB::table('alumnes')->where('id', $id)->update(['variables' => json_encode($vars->pluck('valor', 'clau'))]);
        }
        foreach (DB::table('variables')->where('nivell', 'equip')->get()->groupBy('tipus_equip_id') as $id => $vars) {
            DB::table('tipus_equip')->where('id', $id)->update(['variables_connexio' => json_encode($vars->pluck('valor', 'clau'))]);
        }

        DB::table('variables')->whereIn('nivell', ['alumne', 'equip'])->delete();
        Schema::table('variables', function (Blueprint $table) {
            $table->dropUnique(['alumne_id', 'clau']);
            $table->dropUnique(['tipus_equip_id', 'clau']);
            $table->dropConstrainedForeignId('alumne_id');
            $table->dropConstrainedForeignId('tipus_equip_id');
        });
    }

    private static function text(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) || $value === null => (string) $value,
            default => json_encode($value),
        };
    }
};
