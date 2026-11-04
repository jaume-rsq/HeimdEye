<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Token amb què l'agent d'una instància s'autentica. El genera i el revoca el professor;
        // revocar-lo vol dir regenerar-ne un de nou. No es mostra mai sol: només dins la comanda
        // d'instal·lació que l'alumne copia.
        Schema::table('alumnes', function (Blueprint $table) {
            $table->string('token_agent', 64)->nullable()->unique()->after('es_actiu');
        });

        // Instàncies efímeres que l'alumne registra amb l'agent (AWS Learner Lab i similars).
        // Es donen d'alta en arrencar l'agent i es marquen inactives per manca de batec.
        Schema::create('instancies_agent', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alumne_id')->constrained('alumnes')->cascadeOnDelete();
            $table->string('hostname'); // identifica la instància i fa de grup (# hosts:)
            $table->string('ip_publica')->nullable();
            $table->string('ip_privada')->nullable();
            $table->string('sistema')->nullable(); // p. ex. "Ubuntu 24.04"
            $table->string('versio_agent')->nullable();
            $table->string('estat')->default('activa'); // activa | inactiva
            $table->timestamp('darrer_batec')->nullable();
            $table->timestamp('registrat_a')->nullable();
            $table->timestamps();

            $table->unique(['alumne_id', 'hostname']);
        });

        // Caducitat de la feina d'agent d'una acció (segons). Null = valor per defecte de la config.
        Schema::table('accions', function (Blueprint $table) {
            $table->unsignedInteger('caducitat_agent_seg')->nullable();
        });

        // La feina d'agent reusa execucions_accio: queda pendent fins que l'agent la recull.
        Schema::table('execucions_accio', function (Blueprint $table) {
            $table->foreignId('instancia_id')->nullable()->after('alumne_id')
                ->constrained('instancies_agent')->nullOnDelete();
            $table->string('rol_agent')->nullable()->after('instancia_id'); // hostname de destinació
            $table->timestamp('caduca_a')->nullable()->after('data_fi');
            $table->timestamp('recollit_a')->nullable()->after('caduca_a'); // quan l'agent l'ha recollit
            $table->json('payload_agent')->nullable(); // {code, env, timeout} que executa l'agent
            $table->json('mascara_agent')->nullable(); // valors a emmascarar a la sortida
        });
    }

    public function down(): void
    {
        Schema::table('execucions_accio', function (Blueprint $table) {
            $table->dropConstrainedForeignId('instancia_id');
            $table->dropColumn(['rol_agent', 'caduca_a', 'recollit_a', 'payload_agent', 'mascara_agent']);
        });
        Schema::table('accions', function (Blueprint $table) {
            $table->dropColumn('caducitat_agent_seg');
        });
        Schema::dropIfExists('instancies_agent');
        Schema::table('alumnes', function (Blueprint $table) {
            $table->dropColumn('token_agent');
        });
    }
};
