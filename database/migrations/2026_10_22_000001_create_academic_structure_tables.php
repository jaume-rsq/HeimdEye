<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_years', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique(); // 2026/27
            $table->date('starts_on');
            $table->date('ends_on');
            $table->boolean('is_current')->default(false);
            $table->timestamps();
        });

        Schema::create('cycles', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // SMIX, ASIX
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('credentials', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type'); // App\Enums\CredentialType
            $table->string('username');
            $table->text('secret')->nullable(); // xifrat amb la clau de l'app
            $table->timestamps();
        });

        Schema::create('modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cycle_id')->constrained()->restrictOnDelete();
            $table->string('code')->nullable(); // codi oficial, p. ex. 0227
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('kind'); // App\Enums\ModuleKind
            $table->json('combines')->nullable(); // codis oficials que agrupa un mòdul lliure
            $table->json('derived_vars')->nullable(); // plantilles {{var}} calculades per alumne
            $table->foreignId('credential_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('module_teacher', function (Blueprint $table) {
            $table->foreignId('module_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['module_id', 'user_id']);
        });

        Schema::create('global_vars', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('value');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('global_vars');
        Schema::dropIfExists('module_teacher');
        Schema::dropIfExists('modules');
        Schema::dropIfExists('credentials');
        Schema::dropIfExists('cycles');
        Schema::dropIfExists('academic_years');
    }
};
