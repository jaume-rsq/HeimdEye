<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Comentari opcional de cada versió: què canvia respecte de l'anterior. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['versions_check', 'versions_accio'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->text('comentari')->nullable()->after('codi');
            });
        }
    }

    public function down(): void
    {
        foreach (['versions_check', 'versions_accio'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('comentari');
            });
        }
    }
};
