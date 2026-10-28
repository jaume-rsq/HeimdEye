<?php

namespace Database\Seeders;

use App\Enums\Level;
use App\Enums\ModuleKind;
use App\Enums\Role;
use App\Enums\SecretType;
use App\Models\AcademicYear;
use App\Models\Cycle;
use App\Models\Module;
use App\Models\Secret;
use App\Models\User;
use App\Models\Variable;
use Illuminate\Database\Seeder;

/**
 * Curs 2026/27, cicles, variables globals de l'aula, clau SSH,
 * administrador i el mòdul combinat de SMIX2.
 */
class AcademicStructureSeeder extends Seeder
{
    public function run(): void
    {
        $year = AcademicYear::updateOrCreate(
            ['nom' => '2026/27'],
            ['data_inici' => '2026-09-14', 'data_fi' => '2027-06-30', 'es_actual' => true],
        );

        $smix = Cycle::updateOrCreate(['codi' => 'SMIX'], ['nom' => 'Sistemes Microinformàtics i Xarxes']);
        Cycle::updateOrCreate(['codi' => 'ASIX'], ['nom' => 'Administració de Sistemes Informàtics en Xarxa']);

        foreach ([
            'ip_pve_aula' => '192.168.20.7',
            'ip_dns_aula' => '192.168.20.11',
            'ip_heimdeye' => '192.168.20.12',
            'ip_zabbix' => '192.168.20.12',
            'ip_zentyal' => '192.168.20.254',
            'nom_domini' => 'smix2.julioantonio.cat',
        ] as $key => $value) {
            Variable::updateOrCreate(['nivell' => Level::Global, 'curs_id' => null, 'modul_id' => null, 'clau' => $key], ['valor' => $value]);
        }

        // La clau privada no es versiona: s'afegeix des de l'administració (Inventari → Secrets).
        $sshKey = Secret::firstOrCreate(
            ['nivell' => Level::Global, 'curs_id' => null, 'modul_id' => null, 'clau' => 'ssh_jaume'],
            ['tipus' => SecretType::SshKey, 'nom_usuari' => 'jaume', 'descripcio' => 'Clau SSH de HeimdEye per entrar als equips Linux dels alumnes'],
        );

        $admin = User::updateOrCreate(
            ['email' => config('heimdeye.admin.email')],
            ['nom' => config('heimdeye.admin.name'), 'rol' => Role::Admin],
        );

        $module = Module::updateOrCreate(
            ['curs_id' => $year->id, 'cicle_id' => $smix->id, 'codi' => '9991'],
            [
                'nom' => 'SX+SI+AW',
                'descripcio' => 'Mòdul combinat per Serveis de Xarxa, Seguretat Informàtica i Aplicacions Web',
                'tipus' => ModuleKind::Free,
                'combina' => ['0227', '0226', '0228'],
                'secret_acces_id' => $sshKey->id,
            ],
        );

        // Variables per alumne: xarxa_aula i nom_alum venen de la llista de l'aula (StudentSeeder).
        foreach ([
            'xarxa_lan' => '172.{{x}}.0.0/22',
            'xarxa_dmz' => '10.{{x}}.0.0/22',
            'ip_estacio' => '{{ xarxa_aula | host(2) }}',
            'ip_pve' => '{{ xarxa_aula | host(3) }}',
            'ip_router' => '{{ xarxa_aula | host(-1) }}',
            'ip_dns' => '10.{{x}}.0.11',
            'ip_dnswin' => '172.{{x}}.0.11',
            'num_base' => '{{ xarxa_aula | octet(4) }}',
            'nom_taula' => 'Taula{{num_taula}}',
            'nom_dns' => '{{usuari}}',
        ] as $key => $value) {
            $module->variables()->updateOrCreate(['nivell' => Level::Module, 'clau' => $key], ['valor' => $value]);
        }

        $module->teachers()->syncWithoutDetaching([$admin->id]);
    }
}
