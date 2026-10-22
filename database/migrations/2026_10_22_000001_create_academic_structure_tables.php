<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cursos', function (Blueprint $table) {
            $table->id();
            $table->string('nom')->unique(); // 2026/27
            $table->date('data_inici');
            $table->date('data_fi');
            $table->boolean('es_actual')->default(false);
            $table->timestamps();
        });

        Schema::create('cicles', function (Blueprint $table) {
            $table->id();
            $table->string('codi')->unique(); // SMIX, ASIX
            $table->string('nom');
            $table->timestamps();
        });

        Schema::create('credencials', function (Blueprint $table) {
            $table->id();
            $table->string('nom');
            $table->string('tipus'); // App\Enums\CredentialType
            $table->string('nom_usuari');
            $table->text('secret')->nullable(); // xifrat amb la clau de l'app
            $table->timestamps();
        });

        Schema::create('moduls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('curs_id')->constrained('cursos')->cascadeOnDelete();
            $table->foreignId('cicle_id')->constrained('cicles')->restrictOnDelete();
            $table->string('codi')->nullable(); // codi oficial, p. ex. 0227
            $table->string('nom');
            $table->text('descripcio')->nullable();
            $table->string('tipus'); // App\Enums\ModuleKind
            $table->json('combina')->nullable(); // codis oficials que agrupa un mòdul lliure
            $table->json('variables_derivades')->nullable(); // plantilles {{var}} calculades per alumne
            $table->foreignId('credencial_id')->nullable()->constrained('credencials')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('modul_professor', function (Blueprint $table) {
            $table->foreignId('modul_id')->constrained('moduls')->cascadeOnDelete();
            $table->foreignId('usuari_id')->constrained('usuaris')->cascadeOnDelete();
            $table->primary(['modul_id', 'usuari_id']);
        });

        Schema::create('variables_globals', function (Blueprint $table) {
            $table->id();
            $table->string('clau')->unique();
            $table->string('valor');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('variables_globals');
        Schema::dropIfExists('modul_professor');
        Schema::dropIfExists('moduls');
        Schema::dropIfExists('credencials');
        Schema::dropIfExists('cicles');
        Schema::dropIfExists('cursos');
    }
};
