<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alumnes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuari_id')->nullable()->unique()->constrained('usuaris')->nullOnDelete();
            $table->foreignId('curs_id')->constrained('cursos')->cascadeOnDelete();
            $table->foreignId('cicle_id')->constrained('cicles')->restrictOnDelete();
            $table->string('usuari');
            $table->string('nom_complet');
            $table->string('alies')->nullable(); // nom al leaderboard públic
            $table->unsignedSmallInteger('num_taula')->nullable();
            $table->unsignedSmallInteger('x')->nullable(); // índex de xarxa: 172.X / 10.X
            $table->json('variables')->nullable();
            $table->boolean('es_actiu')->default(true);
            $table->timestamps();

            $table->unique(['curs_id', 'usuari']);
        });

        Schema::create('modul_alumne', function (Blueprint $table) {
            $table->foreignId('alumne_id')->constrained('alumnes')->cascadeOnDelete();
            $table->foreignId('modul_id')->constrained('moduls')->cascadeOnDelete();
            $table->primary(['alumne_id', 'modul_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modul_alumne');
        Schema::dropIfExists('alumnes');
    }
};
