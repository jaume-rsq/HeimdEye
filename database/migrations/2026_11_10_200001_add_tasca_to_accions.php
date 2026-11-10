<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Una acció pot ser d'una tasca (dins del bloc), com els checks i les incidències. Opcional. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accions', function (Blueprint $table) {
            $table->foreignId('tasca_id')->nullable()->after('bloc_id')->constrained('tasques')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('accions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tasca_id');
        });
    }
};
