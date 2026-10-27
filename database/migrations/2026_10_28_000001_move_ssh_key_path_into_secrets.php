<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * La clau SSH deixa de venir del .env: els secrets de clau SSH sense clau privada passen a
 * tenir la ruta de HEIMDEYE_SSH_KEY_PATH (el valor d'un secret de clau SSH pot ser una ruta
 * del servidor), i la clau pública de HEIMDEYE_SSH_PUBKEY_PATH si es pot llegir.
 */
return new class extends Migration
{
    public function up(): void
    {
        $path = config('heimdeye.runner.ssh_key_path');
        if (blank($path)) {
            return;
        }
        $pubkeyPath = config('heimdeye.runner.ssh_pubkey_path');
        $pubkey = $pubkeyPath && is_readable($pubkeyPath) ? trim((string) file_get_contents($pubkeyPath)) : null;

        foreach (DB::table('secrets')->where('tipus', 'clau_ssh')->get() as $secret) {
            $changes = [];
            if (blank($secret->valor)) {
                $changes['valor'] = Crypt::encryptString($path);
            }
            if (blank($secret->clau_publica) && filled($pubkey)) {
                $changes['clau_publica'] = $pubkey;
            }
            if ($changes !== []) {
                DB::table('secrets')->where('id', $secret->id)->update($changes);
            }
        }
    }

    public function down(): void
    {
        // Es queden la ruta i la clau pública: amb el codi d'abans, la ruta es llegiria com a
        // clau, però tampoc no hi ha manera de saber quins valors eren buits.
    }
};
