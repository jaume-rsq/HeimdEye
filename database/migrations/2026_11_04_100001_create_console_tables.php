<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Sessions de consola interactiva d'una instància (fase 3 de l'agent). Model pull, com la
        // resta: el professor obre una sessió, queda pendent fins que l'agent de la instància la
        // recull (long poll) i obre un bash persistent; a partir d'aquí l'entrada i la sortida van
        // pel mateix canal HTTPS de sortida de l'agent. Tot queda registrat (eina docent, auditable).
        Schema::create('sessions_consola_agent', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alumne_id')->constrained('alumnes')->cascadeOnDelete();
            $table->foreignId('instancia_id')->nullable()->constrained('instancies_agent')->nullOnDelete();
            $table->string('rol_agent'); // hostname de la instància on s'obre la consola
            $table->foreignId('obert_per')->nullable()->constrained('usuaris')->nullOnDelete();
            $table->string('estat')->default('pendent'); // pendent | activa | tancada
            $table->timestamp('caduca_a')->nullable(); // finestra per recollir-la
            $table->timestamp('darrera_activitat')->nullable(); // per tancar-la per inactivitat
            $table->timestamp('data_inici')->nullable(); // quan l'agent l'ha recollida
            $table->timestamp('data_fi')->nullable();
            $table->timestamps();

            $table->index(['alumne_id', 'rol_agent', 'estat']);
            $table->index('instancia_id');
        });

        // Registre dels missatges de la sessió, que fa alhora de cua i d'auditoria: l'entrada que
        // escriu el professor i la sortida que torna l'agent, en ordre (id creixent).
        Schema::create('missatges_consola', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sessio_id')->constrained('sessions_consola_agent')->cascadeOnDelete();
            $table->string('direccio'); // entrada (professor -> instància) | sortida (instància -> professor)
            $table->longText('contingut');
            $table->boolean('consumit')->default(false); // entrada: l'agent ja l'ha recollida
            $table->timestamps();

            $table->index(['sessio_id', 'direccio', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('missatges_consola');
        Schema::dropIfExists('sessions_consola_agent');
    }
};
