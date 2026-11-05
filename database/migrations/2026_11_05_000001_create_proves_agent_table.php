<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Proves de l'editor (botó Provar) amb l'executor agent: l'esborrany queda pendent fins que
        // l'agent de la instància el recull. El resultat no es desa com a execució: es deixa a la
        // memòria cau de la prova (RunEditorTest::cacheKey) perquè el reculli la pàgina de l'editor.
        Schema::create('proves_agent', function (Blueprint $table) {
            $table->id();
            $table->string('clau'); // clau de la prova a la memòria cau (una per alumne)
            $table->string('tipus'); // accio | check: com s'interpreta el resultat
            $table->foreignId('alumne_id')->constrained('alumnes')->cascadeOnDelete();
            $table->foreignId('instancia_id')->nullable()->constrained('instancies_agent')->nullOnDelete();
            $table->string('rol_agent');
            $table->string('estat')->default('pendent'); // pendent | enviada | feta | caducada
            $table->json('payload_agent');
            $table->json('mascara_agent')->nullable();
            $table->json('resultat')->nullable(); // {estat, color, resum, sortida} d'aquest rol
            $table->json('extra')->nullable(); // camps de la fila de la prova (comanda, paràmetres)
            $table->timestamp('caduca_a')->nullable();
            $table->timestamp('recollit_a')->nullable();
            $table->timestamps();

            $table->index(['alumne_id', 'rol_agent', 'estat']);
            $table->index('clau');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proves_agent');
    }
};
