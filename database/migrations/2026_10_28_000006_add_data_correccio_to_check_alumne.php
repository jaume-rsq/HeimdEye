<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quan el professor va corregir l'estat d'un check per a un alumne. updated_at no serveix: el runner
 * actualitza la fila a cada execució.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('check_alumne', function (Blueprint $table) {
            $table->timestamp('data_correccio')->nullable()->after('modificat_per');
        });
    }

    public function down(): void
    {
        Schema::table('check_alumne', fn (Blueprint $table) => $table->dropColumn('data_correccio'));
    }
};
