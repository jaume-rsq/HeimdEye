<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('run_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('module_id')->constrained()->cascadeOnDelete();
            $table->string('origin'); // App\Enums\RunOrigin
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('totals')->nullable(); // recompte per estat
            $table->timestamps();
        });

        Schema::create('check_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('check_id')->constrained()->cascadeOnDelete();
            $table->foreignId('check_version_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('status'); // App\Enums\CheckStatus
            $table->string('message')->nullable();
            $table->text('output')->nullable(); // stdout/stderr retallats
            $table->smallInteger('exit_code')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('ran_at');

            $table->index(['check_id', 'student_id', 'ran_at']);
        });

        // Estat actual de cada check per a cada alumne (desnormalitzat per al leaderboard).
        Schema::create('check_student', function (Blueprint $table) {
            $table->id();
            $table->foreignId('check_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('current_status')->nullable(); // null: mai executat
            $table->boolean('teacher_status')->nullable(); // si no és null, preval
            $table->text('teacher_comment')->nullable();
            $table->foreignId('teacher_updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('first_passed_at')->nullable();
            $table->timestamp('last_passed_at')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();

            $table->unique(['check_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('check_student');
        Schema::dropIfExists('check_runs');
        Schema::dropIfExists('run_batches');
    }
};
