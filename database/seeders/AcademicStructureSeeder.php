<?php

namespace Database\Seeders;

use App\Enums\CredentialType;
use App\Enums\ModuleKind;
use App\Enums\Role;
use App\Models\AcademicYear;
use App\Models\Credential;
use App\Models\Cycle;
use App\Models\GlobalVar;
use App\Models\Module;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Curs 2026/27, cicles, variables globals de l'aula, credencial SSH,
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
            'central_ip' => '192.168.20.11',
            'alt_resolver' => '192.168.20.254',
            'heimdeye_ip' => '192.168.20.12',
            'base_domain' => 'smix2.julioantonio.cat',
            'zabbix_server' => '192.168.20.12',
        ] as $key => $value) {
            GlobalVar::updateOrCreate(['clau' => $key], ['valor' => $value]);
        }

        // La clau privada no es versiona: s'afegeix des de l'administració.
        $credential = Credential::firstOrCreate(
            ['nom' => 'SSH jaume'],
            ['tipus' => CredentialType::Ssh, 'nom_usuari' => 'jaume'],
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
                'credencial_id' => $credential->id,
                'variables_derivades' => [
                    'lan' => '172.{{x}}.0.0/22',
                    'dmz' => '10.{{x}}.0.0/22',
                    'pve_ip' => '{{ aula_net | host(3) }}',
                    'router_ip' => '{{ aula_net | host(-1) }}',
                    'dns_ip' => '10.{{x}}.0.11',
                    'dnswin_ip' => '172.{{x}}.0.11',
                    // Mateixos noms que l'inventari Ansible de l'aula (smix2.ini).
                    'base' => '{{ aula_net | octet(4) }}',
                    'xarxa' => '{{aula_net}}',
                    'taula' => 'Taula{{num_taula}}',
                    'alumne' => '{{nom_complet}}',
                    'nom_dns' => '{{usuari}}',
                ],
            ],
        );

        $module->teachers()->syncWithoutDetaching([$admin->id]);
    }
}
