<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * L'executor es pot canviar: cada versió desa amb quin executor es va escriure. Les versions
 * existents prenen el del seu check o acció (que fins ara no es podia canviar).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['versions_check', 'versions_accio'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('executor')->nullable()->after('versio');
            });
        }

        DB::statement('UPDATE versions_check SET executor = (SELECT checks.executor FROM checks WHERE checks.id = versions_check.check_id)');
        DB::statement('UPDATE versions_accio SET executor = (SELECT accions.executor FROM accions WHERE accions.id = versions_accio.accio_id)');
    }

    public function down(): void
    {
        foreach (['versions_check', 'versions_accio'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('executor');
            });
        }
    }
};
