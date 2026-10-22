<?php

namespace Database\Seeders;

use App\Enums\CredentialType;
use App\Enums\Executor;
use App\Enums\HostScope;
use App\Models\Credential;
use App\Models\Module;
use Illuminate\Database\Seeder;

/**
 * Tipus d'equip, secrets i accions del mòdul 9991, equivalents a l'inventari smix2.ini.
 * Les contrasenyes no es versionen: s'omplen des de l'administració.
 */
class HostsSeeder extends Seeder
{
    public function run(): void
    {
        $module = Module::where('codi', '9991')->firstOrFail();

        $windows = Credential::firstOrCreate(
            ['nom' => 'WinRM Windows Server'],
            ['tipus' => CredentialType::Winrm, 'nom_usuari' => 'Administrador'],
        );
        $zabbix = Credential::firstOrCreate(
            ['nom' => 'API Zabbix'],
            ['tipus' => CredentialType::Password, 'nom_usuari' => 'ansible'],
        );

        $winrm = [
            'ansible_connection' => 'winrm',
            'ansible_winrm_transport' => 'ntlm',
            'ansible_winrm_server_cert_validation' => 'ignore',
            'ansible_port' => 5985,
        ];

        $types = [
            ['nom' => 'pve', 'grup_ansible' => 'pve', 'nom_host' => 'pve{{num_taula}}-{{usuari}}', 'adreca' => '{{pve_ip}}'],
            ['nom' => 'router', 'grup_ansible' => 'vm_router', 'nom_host' => 'router{{num_taula}}', 'adreca' => '{{router_ip}}'],
            ['nom' => 'dns', 'grup_ansible' => 'vm_dns_bind', 'nom_host' => 'dns{{num_taula}}', 'adreca' => '{{dns_ip}}'],
            [
                'nom' => 'dnswin', 'grup_ansible' => 'vm_dns_windows', 'nom_host' => 'dnswin{{num_taula}}', 'adreca' => '{{dnswin_ip}}',
                'credencial_id' => $windows->id,
                'variables_connexio' => $winrm,
            ],
            [
                'nom' => 'estacio', 'grup_ansible' => 'estacio_windows', 'nom_host' => 'estacio{{num_taula}}', 'adreca' => '{{estacio_ip}}',
                'credencial_id' => $windows->id,
                'variables_connexio' => $winrm,
            ],
            [
                'nom' => 'zbx', 'grup_ansible' => 'zabbix', 'abast' => HostScope::Global, 'nom_host' => 'zbx', 'adreca' => '{{zabbix_server}}',
                'credencial_id' => $zabbix->id,
                'variables_connexio' => [
                    'ansible_connection' => 'httpapi',
                    'ansible_network_os' => 'community.zabbix.zabbix',
                    'ansible_httpapi_port' => 80,
                    'ansible_httpapi_use_ssl' => false,
                    'ansible_zabbix_url_path' => 'zabbix',
                ],
            ],
        ];
        foreach ($types as $position => $type) {
            $module->hostTypes()->updateOrCreate(['nom' => $type['nom']], [...$type, 'posicio' => $position + 1]);
        }

        $module->secrets()->firstOrCreate(
            ['clau' => 'jaume_password'],
            ['descripcio' => 'Contrasenya de l\'usuari jaume als equips Linux dels alumnes'],
        );

        $action = $module->actions()->updateOrCreate(
            ['nom' => 'Contrasenya de l\'usuari jaume'],
            [
                'descripcio' => 'Posa la contrasenya del secret jaume_password a l\'usuari jaume (que entra amb clau).',
                'executor' => Executor::Ansible,
                'posicio' => 1,
            ],
        );
        if (! $action->versions()->exists()) {
            $action->versions()->create([
                'versio' => 1,
                'codi' => <<<'YAML'
                    - hosts: pve:vm_router:vm_dns_bind
                      become: true
                      tasks:
                        - name: Contrasenya de jaume
                          ansible.builtin.user:
                            name: jaume
                            # Sal fixa perquè el hash no canviï a cada execució (idempotent).
                            password: "{{ jaume_password | password_hash('sha512', 'heimdeye') }}"
                    YAML,
            ]);
        }
    }
}
