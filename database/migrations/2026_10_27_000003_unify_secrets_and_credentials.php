<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Secrets i credencials passen a ser una sola cosa: un secret amb tipus (valor, usuari i
 * contrasenya, clau SSH) i nivell (global, curs, mòdul), com les variables. Les credencials
 * esdevenen secrets globals, i mòduls i tipus d'equip en diuen l'accés amb secret_acces_id.
 * "abast" de les variables passa a dir-se "nivell".
 */
return new class extends Migration
{
    private const TYPES = ['ssh' => 'clau_ssh', 'winrm' => 'usuari_contrasenya', 'contrasenya' => 'usuari_contrasenya'];

    public function up(): void
    {
        Schema::table('variables', function (Blueprint $table) {
            $table->dropUnique(['abast', 'curs_id', 'modul_id', 'clau']);
        });
        Schema::table('variables', fn (Blueprint $table) => $table->renameColumn('abast', 'nivell'));
        Schema::table('variables', fn (Blueprint $table) => $table->unique(['nivell', 'curs_id', 'modul_id', 'clau']));

        Schema::table('secrets', function (Blueprint $table) {
            $table->dropUnique(['modul_id', 'clau']);
        });
        Schema::table('secrets', function (Blueprint $table) {
            $table->string('nivell')->default('modul'); // App\Enums\Level
            $table->foreignId('curs_id')->nullable()->constrained('cursos')->cascadeOnDelete();
            $table->string('tipus')->default('valor'); // App\Enums\SecretType
            $table->string('nom_usuari')->nullable();
            $table->text('clau_publica')->nullable(); // clau SSH: no és secreta
            $table->unsignedBigInteger('modul_id')->nullable()->change();
        });
        Schema::table('secrets', fn (Blueprint $table) => $table->unique(['nivell', 'curs_id', 'modul_id', 'clau']));

        $now = now();
        $taken = DB::table('secrets')->where('nivell', 'global')->pluck('clau')->all();
        $map = [];
        foreach (DB::table('credencials')->orderBy('id')->get() as $credential) {
            $key = $base = Str::slug($credential->nom, '_') ?: 'credencial';
            for ($i = 2; in_array($key, $taken, true); $i++) {
                $key = "{$base}_{$i}";
            }
            $taken[] = $key;
            $map[$credential->id] = DB::table('secrets')->insertGetId([
                'nivell' => 'global',
                'clau' => $key,
                'tipus' => self::TYPES[$credential->tipus] ?? 'usuari_contrasenya',
                'nom_usuari' => $credential->nom_usuari,
                'valor' => $credential->secret, // ja xifrat amb la mateixa clau
                'descripcio' => $credential->nom,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach (['moduls', 'tipus_equip'] as $tableName) {
            Schema::table($tableName, fn (Blueprint $table) => $table->foreignId('secret_acces_id')->nullable()->constrained('secrets')->nullOnDelete());
            foreach (DB::table($tableName)->whereNotNull('credencial_id')->get(['id', 'credencial_id']) as $row) {
                DB::table($tableName)->where('id', $row->id)->update(['secret_acces_id' => $map[$row->credencial_id] ?? null]);
            }
            Schema::table($tableName, fn (Blueprint $table) => $table->dropConstrainedForeignId('credencial_id'));
        }

        Schema::drop('credencials');
    }

    public function down(): void
    {
        Schema::create('credencials', function (Blueprint $table) {
            $table->id();
            $table->string('nom')->unique();
            $table->string('tipus');
            $table->string('nom_usuari');
            $table->text('secret')->nullable();
            $table->timestamps();
        });

        $now = now();
        $map = [];
        foreach (DB::table('secrets')->where('tipus', '!=', 'valor')->orderBy('id')->get() as $secret) {
            $map[$secret->id] = DB::table('credencials')->insertGetId([
                'nom' => $secret->clau,
                'tipus' => $secret->tipus === 'clau_ssh' ? 'ssh' : 'contrasenya',
                'nom_usuari' => $secret->nom_usuari ?? '',
                'secret' => $secret->valor,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach (['moduls', 'tipus_equip'] as $tableName) {
            Schema::table($tableName, fn (Blueprint $table) => $table->foreignId('credencial_id')->nullable()->constrained('credencials')->nullOnDelete());
            foreach (DB::table($tableName)->whereNotNull('secret_acces_id')->get(['id', 'secret_acces_id']) as $row) {
                DB::table($tableName)->where('id', $row->id)->update(['credencial_id' => $map[$row->secret_acces_id] ?? null]);
            }
            Schema::table($tableName, fn (Blueprint $table) => $table->dropConstrainedForeignId('secret_acces_id'));
        }

        DB::table('secrets')->where(fn ($q) => $q->where('tipus', '!=', 'valor')->orWhere('nivell', '!=', 'modul'))->delete();
        Schema::table('secrets', fn (Blueprint $table) => $table->dropUnique(['nivell', 'curs_id', 'modul_id', 'clau']));
        Schema::table('secrets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('curs_id');
            $table->dropColumn(['nivell', 'tipus', 'nom_usuari', 'clau_publica']);
        });
        Schema::table('secrets', fn (Blueprint $table) => $table->unique(['modul_id', 'clau']));

        Schema::table('variables', fn (Blueprint $table) => $table->dropUnique(['nivell', 'curs_id', 'modul_id', 'clau']));
        Schema::table('variables', fn (Blueprint $table) => $table->renameColumn('nivell', 'abast'));
        Schema::table('variables', fn (Blueprint $table) => $table->unique(['abast', 'curs_id', 'modul_id', 'clau']));
    }
};
