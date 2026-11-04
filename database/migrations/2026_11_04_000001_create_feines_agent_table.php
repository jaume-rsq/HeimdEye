<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cua de la feina de checks que s'executa per agent (llançada des de HeimdEye). Es manté a
        // part de les execucions_check perquè l'estat pendent/caducat no embruti CheckStatus ni els
        // càlculs de leaderboard i punts: quan arriba el resultat, es desa un CheckRun normal.
        Schema::create('feines_agent', function (Blueprint $table) {
            $table->id();
            $table->foreignId('execucio_id')->constrained('execucions')->cascadeOnDelete();
            $table->foreignId('check_id')->constrained('checks')->cascadeOnDelete();
            $table->foreignId('versio_check_id')->nullable()->constrained('versions_check')->nullOnDelete();
            $table->foreignId('alumne_id')->constrained('alumnes')->cascadeOnDelete();
            $table->foreignId('instancia_id')->nullable()->constrained('instancies_agent')->nullOnDelete();
            $table->string('rol_agent'); // hostname de la instància on s'ha d'executar
            $table->string('estat')->default('pendent'); // pendent | enviada | feta | caducada
            $table->json('payload_agent'); // {code, env, timeout} que executa l'agent
            $table->json('mascara_agent')->nullable(); // valors a emmascarar a la sortida
            $table->timestamp('caduca_a')->nullable();
            $table->timestamp('recollit_a')->nullable();
            $table->timestamps();

            $table->index(['alumne_id', 'rol_agent', 'estat']);
            $table->index('instancia_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feines_agent');
    }
};
