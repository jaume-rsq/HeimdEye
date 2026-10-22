<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blocs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('modul_id')->constrained('moduls')->cascadeOnDelete();
            $table->unsignedSmallInteger('numero');
            $table->string('titol');
            $table->date('data_inici')->nullable();
            $table->date('data_fi')->nullable();
            $table->unsignedSmallInteger('posicio')->default(0);
            $table->timestamps();

            $table->unique(['modul_id', 'numero']);
        });

        Schema::create('tasques', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bloc_id')->constrained('blocs')->cascadeOnDelete();
            $table->string('titol');
            $table->text('enunciat')->nullable(); // Markdown
            $table->unsignedSmallInteger('posicio')->default(0);
            $table->timestamps();
        });

        Schema::create('checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tasca_id')->constrained('tasques')->cascadeOnDelete();
            $table->string('nom');
            $table->text('descripcio')->nullable();
            $table->string('executor'); // App\Enums\Executor
            $table->string('tipus')->default('obligatori'); // App\Enums\CheckKind
            $table->unsignedInteger('punts')->default(1);
            $table->boolean('es_actiu')->nullable(); // null: mana l'interval de dates
            $table->date('actiu_des')->nullable();
            $table->date('actiu_fins')->nullable();
            $table->dateTime('data_limit')->nullable();
            $table->unsignedSmallInteger('bonus_puntualitat_pct')->default(0);
            $table->unsignedSmallInteger('temps_max_seg')->default(15);
            $table->foreignId('credencial_id')->nullable()->constrained('credencials')->nullOnDelete(); // sobreescriu la del mòdul
            $table->unsignedSmallInteger('posicio')->default(0);
            $table->timestamps();
        });

        // La versió vigent d'un check és la de número més alt.
        Schema::create('versions_check', function (Blueprint $table) {
            $table->id();
            $table->foreignId('check_id')->constrained('checks')->cascadeOnDelete();
            $table->unsignedInteger('versio');
            $table->text('codi');
            $table->json('configuracio')->nullable(); // host_type, port, url...
            $table->foreignId('creat_per')->nullable()->constrained('usuaris')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['check_id', 'versio']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('versions_check');
        Schema::dropIfExists('checks');
        Schema::dropIfExists('tasques');
        Schema::dropIfExists('blocs');
    }
};
