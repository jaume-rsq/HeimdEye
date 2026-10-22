<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Equips sobre els quals s'executen checks i accions; generen l'inventari Ansible.
        Schema::create('host_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('module_id')->constrained()->cascadeOnDelete();
            $table->string('name'); // pve, router, dns...
            $table->string('ansible_group'); // pve, vm_router...
            $table->string('scope')->default('student'); // App\Enums\HostScope
            $table->string('host_name'); // plantilla: pve{{num_taula}}-{{username}}
            $table->string('address'); // plantilla: {{ aula_net | host(3) }}
            $table->json('connection_vars')->nullable(); // ansible_connection, ansible_port...
            $table->foreignId('credential_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['module_id', 'name']);
        });

        // Fan de vault: es passen a Ansible i als scripts, i s'emmascaren a la sortida.
        Schema::create('secrets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('module_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->text('value')->nullable(); // xifrat amb la clau de l'app
            $table->string('description')->nullable();
            $table->timestamps();

            $table->unique(['module_id', 'key']);
        });

        Schema::create('actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('module_id')->constrained()->cascadeOnDelete();
            $table->foreignId('block_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('executor')->default('ansible'); // App\Enums\Executor
            $table->json('params')->nullable(); // [{name, label, default}]
            $table->json('verify_check_ids')->nullable(); // checks a llançar després
            $table->unsignedSmallInteger('timeout_s')->default(300);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('action_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('action_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->text('code');
            $table->json('config')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['action_id', 'version']);
        });

        Schema::create('action_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('action_id')->constrained()->cascadeOnDelete();
            $table->foreignId('action_version_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('check_mode')->default(false); // simulació (--check --diff)
            $table->string('status'); // App\Enums\ActionStatus
            $table->unsignedInteger('ok')->nullable();
            $table->unsignedInteger('changed')->nullable();
            $table->unsignedInteger('failed')->nullable();
            $table->json('params')->nullable();
            $table->text('output')->nullable(); // amb els secrets emmascarats
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->index(['action_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('action_runs');
        Schema::dropIfExists('action_versions');
        Schema::dropIfExists('actions');
        Schema::dropIfExists('secrets');
        Schema::dropIfExists('host_types');
    }
};
