<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Incidències: avaries intencionades que el professor injecta als equips dels alumnes perquè les
 * resolguin. Una incidència té diverses opcions (acció + paràmetres + check de resolució); en cada
 * llançament se'n tria una o més per alumne i es deixa constància de com acaba cadascuna.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accions', function (Blueprint $table) {
            $table->boolean('es_incidencia')->default(false); // acció d'avaria: confirmació extra
        });

        Schema::create('incidencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('modul_id')->constrained('moduls')->cascadeOnDelete();
            $table->foreignId('bloc_id')->nullable()->constrained('blocs')->nullOnDelete();
            $table->string('nom');
            $table->string('equip')->nullable(); // tipus d'equip (ssh/ansible) o rol d'agent on s'executa
            $table->text('simptoma'); // el que llegeix l'alumne
            $table->text('notes')->nullable(); // només el professor
            $table->unsignedSmallInteger('posicio')->default(0);
            $table->timestamps();
        });

        Schema::create('opcions_incidencia', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incidencia_id')->constrained('incidencies')->cascadeOnDelete();
            $table->string('nom');
            $table->foreignId('accio_id')->constrained('accions')->cascadeOnDelete();
            $table->json('parametres')->nullable(); // {nom: valor}, sobre els per defecte de l'acció
            $table->foreignId('check_id')->nullable()->constrained('checks')->nullOnDelete(); // resolució
            $table->foreignId('accio_reparacio_id')->nullable()->constrained('accions')->nullOnDelete();
            $table->unsignedSmallInteger('pes')->default(1);
            $table->unsignedSmallInteger('posicio')->default(0);
            $table->timestamps();
        });

        Schema::create('llancaments_incidencia', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incidencia_id')->constrained('incidencies')->cascadeOnDelete();
            $table->foreignId('llancat_per')->nullable()->constrained('usuaris')->nullOnDelete();
            $table->string('mode'); // aleatori, igual, manual
            $table->unsignedSmallInteger('quantitat')->default(1); // opcions per alumne
            $table->timestamp('data_limit')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('incidencies_alumne', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incidencia_id')->constrained('incidencies')->cascadeOnDelete();
            $table->foreignId('llancament_id')->constrained('llancaments_incidencia')->cascadeOnDelete();
            $table->foreignId('opcio_id')->nullable()->constrained('opcions_incidencia')->nullOnDelete();
            $table->string('nom_opcio'); // per si l'opció s'esborra després
            $table->foreignId('alumne_id')->constrained('alumnes')->cascadeOnDelete();
            $table->foreignId('execucio_id')->nullable()->constrained('execucions')->nullOnDelete(); // lot de l'acció
            $table->string('estat'); // App\Enums\IncidentStatus
            $table->string('missatge')->nullable();
            $table->timestamp('data_injeccio')->nullable();
            $table->timestamp('data_resolucio')->nullable();
            $table->foreignId('execucio_check_id')->nullable()->constrained('execucions_check')->nullOnDelete();
            $table->foreignId('tancat_per')->nullable()->constrained('usuaris')->nullOnDelete();
            $table->boolean('es_revelada')->default(false);
            $table->text('comentari_professor')->nullable();
            $table->timestamps();

            $table->index(['alumne_id', 'estat']);
            $table->index(['execucio_id', 'alumne_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incidencies_alumne');
        Schema::dropIfExists('llancaments_incidencia');
        Schema::dropIfExists('opcions_incidencia');
        Schema::dropIfExists('incidencies');
        Schema::table('accions', function (Blueprint $table) {
            $table->dropColumn('es_incidencia');
        });
    }
};
