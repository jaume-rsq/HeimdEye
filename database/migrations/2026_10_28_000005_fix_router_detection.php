<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Els checks dels blocs 5 i 8 detecten el router amb ssh ... ':put routeros' i prenien qualsevol
 * codi de sortida diferent de 0 per «HeimdEye no pot entrar». Un router Linux hi entra però respon
 * 127 (":put" no existeix), així que sortia com a error. Només 255 vol dir que ssh no connecta.
 *
 * Crea una versió nova dels checks la versió vigent dels quals encara té la línia antiga.
 */
return new class extends Migration
{
    private const OLD = 'if [ $rc -ne 0 ]; then sistema=cap';

    private const NEW = "# Només 255 vol dir que ssh no entra: un Linux respon 127 perquè «:put» no hi existeix.\nif [ \$rc -eq 255 ]; then sistema=cap";

    public function up(): void
    {
        $current = DB::table('versions_check')
            ->select('check_id', DB::raw('max(versio) as versio'))
            ->groupBy('check_id');

        $rows = DB::table('versions_check')
            ->joinSub($current, 'vigent', fn ($join) => $join
                ->on('vigent.check_id', '=', 'versions_check.check_id')
                ->on('vigent.versio', '=', 'versions_check.versio'))
            ->where('codi', 'like', '%'.self::OLD.'%')
            ->select('versions_check.*')
            ->get();

        foreach ($rows as $row) {
            $new = (array) $row;
            unset($new['id']);
            $new['versio'] = $row->versio + 1;
            $new['codi'] = str_replace(self::OLD, self::NEW, $row->codi);
            $new['comentari'] = 'Detecció del router: un Linux ja no surt com «HeimdEye no pot entrar»';
            $new['creat_per'] = null;
            $new['created_at'] = now();
            DB::table('versions_check')->insert($new);
        }
    }

    public function down(): void
    {
        // Les versions noves es queden: són les correctes.
    }
};
