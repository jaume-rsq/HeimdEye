<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cycle_id')->constrained()->restrictOnDelete();
            $table->string('username');
            $table->string('full_name');
            $table->string('display_name')->nullable(); // nom al leaderboard públic
            $table->unsignedSmallInteger('table_no')->nullable();
            $table->unsignedSmallInteger('x')->nullable(); // índex de xarxa: 172.X / 10.X
            $table->json('vars')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['academic_year_id', 'username']);
        });

        Schema::create('enrollments', function (Blueprint $table) {
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('module_id')->constrained()->cascadeOnDelete();
            $table->primary(['student_id', 'module_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollments');
        Schema::dropIfExists('students');
    }
};
