<?php

use App\Enums\Executor;
use App\Enums\SecretType;
use App\Models\Module;
use App\Models\Secret;
use App\Runner\SecretRefs;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * La capçalera "# credencial:" i heimdeye_ssh_pubkey_file ja no existeixen. Reescriu el codi desat
 * (totes les versions) a la forma nova quan es pot fer sense canviar el que fa:
 *
 * - local: treu la capçalera i canvia $HE_CRED_USER/$HE_CRED_PASSWORD o $HE_SSH_USER/$HE_SSH_KEY
 *   pel secret mateix ($HE_SECRET_<NOM>_USER, _PASSWORD, _KEY_FILE);
 * - ssh: treu la capçalera si el secret ja és l'accés del mòdul;
 * - Ansible: lookup('file', heimdeye_ssh_pubkey_file) passa a ser <accés del mòdul>.pubkey.
 *
 * El que no es pot reescriure es queda igual, i en executar-lo dona un error que diu com canviar-ho.
 */
return new class extends Migration
{
    private const HEADER = '/^[ \t]*#\s*credencial\s*:\s*(.*?)\s*$\R?/mi';

    public function up(): void
    {
        $checks = DB::table('versions_check')
            ->join('checks', 'checks.id', '=', 'versions_check.check_id')
            ->join('tasques', 'tasques.id', '=', 'checks.tasca_id')
            ->join('blocs', 'blocs.id', '=', 'tasques.bloc_id')
            ->select('versions_check.id', 'versions_check.codi', 'checks.executor', 'blocs.modul_id');
        $actions = DB::table('versions_accio')
            ->join('accions', 'accions.id', '=', 'versions_accio.accio_id')
            ->select('versions_accio.id', 'versions_accio.codi', 'accions.executor', 'accions.modul_id');

        foreach (['versions_check' => $checks, 'versions_accio' => $actions] as $table => $query) {
            foreach ($query->get() as $row) {
                if (! str_contains($row->codi, 'credencial') && ! str_contains($row->codi, 'heimdeye_ssh_pubkey_file')) {
                    continue;
                }
                $module = Module::find($row->modul_id);
                $code = $module === null ? $row->codi : $this->rewrite($row->codi, Executor::from($row->executor), $module);
                if ($code !== $row->codi) {
                    DB::table($table)->where('id', $row->id)->update(['codi' => $code]);
                }
            }
        }
    }

    public function down(): void
    {
        // El codi reescrit fa el mateix que l'original: no cal desfer-ho.
    }

    private function rewrite(string $code, Executor $executor, Module $module): string
    {
        if ($executor === Executor::Ansible) {
            $access = $module->access;

            return $access?->tipus === SecretType::SshKey
                ? preg_replace('/lookup\(\s*[\'"]file[\'"]\s*,\s*heimdeye_ssh_pubkey_file\s*\)/', "{$access->clau}.pubkey", $code)
                : $code;
        }

        if (! preg_match(self::HEADER, $code, $m)) {
            return $code;
        }
        $secrets = $module->visibleSecrets();
        $secret = $secrets->get($m[1]) ?? $secrets->get(Str::slug($m[1], '_'));
        if (! $secret instanceof Secret || ! $secret->tipus->isAccess()) {
            return $code;
        }

        if ($executor === Executor::Ssh) {
            return $secret->id === $module->secret_acces_id ? preg_replace(self::HEADER, '', $code, 1) : $code;
        }

        $names = $secret->tipus === SecretType::Login
            ? ['HE_CRED_USER' => 'user', 'HE_CRED_PASSWORD' => 'password']
            : ['HE_SSH_USER' => 'user', 'HE_SSH_KEY' => 'key_file'];
        $code = preg_replace(self::HEADER, '', $code, 1);
        foreach ($names as $old => $field) {
            $code = preg_replace('/\b'.$old.'\b/', SecretRefs::envName($secret->clau, $field), $code);
        }

        return $code;
    }
};
