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
            ['name' => '2026/27'],
            ['starts_on' => '2026-09-14', 'ends_on' => '2027-06-30', 'is_current' => true],
        );

        $smix = Cycle::updateOrCreate(['code' => 'SMIX'], ['name' => 'Sistemes Microinformàtics i Xarxes']);
        Cycle::updateOrCreate(['code' => 'ASIX'], ['name' => 'Administració de Sistemes Informàtics en Xarxa']);

        foreach ([
            'central_ip' => '192.168.20.11',
            'alt_resolver' => '192.168.20.254',
            'heimdeye_ip' => '192.168.20.12',
            'base_domain' => 'smix2.julioantonio.cat',
        ] as $key => $value) {
            GlobalVar::updateOrCreate(['key' => $key], ['value' => $value]);
        }

        // La clau privada no es versiona: s'afegeix des de l'administració.
        $credential = Credential::firstOrCreate(
            ['name' => 'SSH jaume'],
            ['type' => CredentialType::Ssh, 'username' => 'jaume'],
        );

        $admin = User::updateOrCreate(
            ['email' => config('heimdeye.admin.email')],
            ['name' => config('heimdeye.admin.name'), 'role' => Role::Admin],
        );

        $module = Module::updateOrCreate(
            ['academic_year_id' => $year->id, 'cycle_id' => $smix->id, 'code' => '9991'],
            [
                'name' => 'SX+SI+AW',
                'description' => 'Mòdul combinat per Serveis de Xarxa, Seguretat Informàtica i Aplicacions Web',
                'kind' => ModuleKind::Free,
                'combines' => ['0227', '0226', '0228'],
                'credential_id' => $credential->id,
                'derived_vars' => [
                    'lan' => '172.{{x}}.0.0/22',
                    'dmz' => '10.{{x}}.0.0/22',
                    'pve_ip' => '{{ aula_net | host(3) }}',
                    'router_ip' => '{{ aula_net | host(-1) }}',
                ],
            ],
        );

        $module->teachers()->syncWithoutDetaching([$admin->id]);
    }
}
