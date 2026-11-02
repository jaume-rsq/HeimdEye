<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Grups-classe (1r SMIX, 2n SMIX...): cada curs té els seus. Un alumne és d'un grup i es matricula
 * als mòduls que sigui; el grup serveix per filtrar el leaderboard i el seguiment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('curs_id')->constrained('cursos')->cascadeOnDelete();
            $table->foreignId('cicle_id')->nullable()->constrained('cicles')->nullOnDelete();
            $table->string('codi', 30);
            $table->string('nom')->nullable();
            $table->timestamps();
            $table->unique(['curs_id', 'codi']);
        });

        Schema::table('alumnes', function (Blueprint $table) {
            $table->foreignId('grup_id')->nullable()->after('cicle_id')->constrained('grups')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('alumnes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('grup_id');
        });
        Schema::dropIfExists('grups');
    }
};
