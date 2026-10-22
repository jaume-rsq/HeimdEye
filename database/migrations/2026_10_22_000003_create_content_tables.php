<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('module_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->string('title');
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['module_id', 'number']);
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('block_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('statement')->nullable(); // Markdown
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('executor'); // App\Enums\Executor
            $table->string('kind')->default('required'); // App\Enums\CheckKind
            $table->unsignedInteger('points')->default(1);
            $table->boolean('active')->nullable(); // null: mana l'interval de dates
            $table->date('active_from')->nullable();
            $table->date('active_to')->nullable();
            $table->dateTime('deadline')->nullable();
            $table->unsignedSmallInteger('punctuality_bonus_pct')->default(0);
            $table->unsignedSmallInteger('timeout_s')->default(15);
            $table->foreignId('credential_id')->nullable()->constrained()->nullOnDelete(); // sobreescriu la del mòdul
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        // La versió vigent d'un check és la de número més alt.
        Schema::create('check_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('check_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->text('code');
            $table->json('config')->nullable(); // host, port, url...
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['check_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('check_versions');
        Schema::dropIfExists('checks');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('blocks');
    }
};
