<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Equips sobre els quals s'executen checks i accions; generen l'inventari Ansible.
        Schema::create('tipus_equip', function (Blueprint $table) {
            $table->id();
            $table->foreignId('modul_id')->constrained('moduls')->cascadeOnDelete();
            $table->string('nom'); // pve, router, dns...
            $table->string('grup_ansible'); // pve, vm_router...
            $table->string('abast')->default('alumne'); // App\Enums\HostScope
            $table->string('nom_host'); // plantilla: pve{{num_taula}}-{{usuari}}
            $table->string('adreca'); // plantilla: {{ aula_net | host(3) }}
            $table->json('variables_connexio')->nullable(); // ansible_connection, ansible_port...
            $table->foreignId('credencial_id')->nullable()->constrained('credencials')->nullOnDelete();
            $table->unsignedSmallInteger('posicio')->default(0);
            $table->timestamps();

            $table->unique(['modul_id', 'nom']);
        });

        // Fan de vault: es passen a Ansible i als scripts, i s'emmascaren a la sortida.
        Schema::create('secrets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('modul_id')->constrained('moduls')->cascadeOnDelete();
            $table->string('clau');
            $table->text('valor')->nullable(); // xifrat amb la clau de l'app
            $table->string('descripcio')->nullable();
            $table->timestamps();

            $table->unique(['modul_id', 'clau']);
        });

        Schema::create('accions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('modul_id')->constrained('moduls')->cascadeOnDelete();
            $table->foreignId('bloc_id')->nullable()->constrained('blocs')->nullOnDelete();
            $table->string('nom');
            $table->text('descripcio')->nullable();
            $table->string('executor')->default('ansible'); // App\Enums\Executor
            $table->json('parametres')->nullable(); // [{name, label, default}]
            $table->json('checks_verificacio')->nullable(); // checks a llançar després
            $table->unsignedSmallInteger('temps_max_seg')->default(300);
            $table->unsignedSmallInteger('posicio')->default(0);
            $table->timestamps();
        });

        Schema::create('versions_accio', function (Blueprint $table) {
            $table->id();
            $table->foreignId('accio_id')->constrained('accions')->cascadeOnDelete();
            $table->unsignedInteger('versio');
            $table->text('codi');
            $table->json('configuracio')->nullable();
            $table->foreignId('creat_per')->nullable()->constrained('usuaris')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['accio_id', 'versio']);
        });

        Schema::create('execucions_accio', function (Blueprint $table) {
            $table->id();
            $table->foreignId('execucio_id')->constrained('execucions')->cascadeOnDelete();
            $table->foreignId('accio_id')->constrained('accions')->cascadeOnDelete();
            $table->foreignId('versio_accio_id')->nullable()->constrained('versions_accio')->nullOnDelete();
            $table->foreignId('alumne_id')->constrained('alumnes')->cascadeOnDelete();
            $table->foreignId('llancat_per')->nullable()->constrained('usuaris')->nullOnDelete();
            $table->boolean('es_simulacio')->default(false); // --check --diff
            $table->string('estat'); // App\Enums\ActionStatus
            $table->unsignedInteger('ok')->nullable();
            $table->unsignedInteger('changed')->nullable();
            $table->unsignedInteger('failed')->nullable();
            $table->json('parametres')->nullable();
            $table->text('sortida')->nullable(); // amb els secrets emmascarats
            $table->timestamp('data_inici')->nullable();
            $table->timestamp('data_fi')->nullable();

            $table->index(['accio_id', 'alumne_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('execucions_accio');
        Schema::dropIfExists('versions_accio');
        Schema::dropIfExists('accions');
        Schema::dropIfExists('secrets');
        Schema::dropIfExists('tipus_equip');
    }
};
