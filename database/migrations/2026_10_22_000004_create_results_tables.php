<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('execucions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('modul_id')->constrained('moduls')->cascadeOnDelete();
            $table->string('origen'); // App\Enums\RunOrigin
            $table->foreignId('llancat_per')->nullable()->constrained('usuaris')->nullOnDelete();
            $table->timestamp('data_inici')->nullable();
            $table->timestamp('data_fi')->nullable();
            $table->json('totals')->nullable(); // recompte per estat
            $table->timestamps();
        });

        Schema::create('execucions_check', function (Blueprint $table) {
            $table->id();
            $table->foreignId('execucio_id')->constrained('execucions')->cascadeOnDelete();
            $table->foreignId('check_id')->constrained('checks')->cascadeOnDelete();
            $table->foreignId('versio_check_id')->nullable()->constrained('versions_check')->nullOnDelete();
            $table->foreignId('alumne_id')->constrained('alumnes')->cascadeOnDelete();
            $table->string('estat'); // App\Enums\CheckStatus
            $table->string('missatge')->nullable();
            $table->text('sortida')->nullable(); // stdout/stderr retallats
            $table->smallInteger('codi_sortida')->nullable();
            $table->unsignedInteger('durada_ms')->nullable();
            $table->timestamp('data_execucio');

            $table->index(['check_id', 'alumne_id', 'data_execucio']);
        });

        // Estat actual de cada check per a cada alumne (desnormalitzat per al leaderboard).
        Schema::create('check_alumne', function (Blueprint $table) {
            $table->id();
            $table->foreignId('check_id')->constrained('checks')->cascadeOnDelete();
            $table->foreignId('alumne_id')->constrained('alumnes')->cascadeOnDelete();
            $table->string('estat_actual')->nullable(); // null: mai executat
            $table->boolean('estat_professor')->nullable(); // si no és null, preval
            $table->text('comentari_professor')->nullable();
            $table->foreignId('modificat_per')->nullable()->constrained('usuaris')->nullOnDelete();
            $table->timestamp('data_primer_assoliment')->nullable();
            $table->timestamp('data_ultim_assoliment')->nullable();
            $table->timestamp('data_darrera_execucio')->nullable();
            $table->timestamps();

            $table->unique(['check_id', 'alumne_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('check_alumne');
        Schema::dropIfExists('execucions_check');
        Schema::dropIfExists('execucions');
    }
};
