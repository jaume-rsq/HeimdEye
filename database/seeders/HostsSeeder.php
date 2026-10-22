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
        $module = Module::where('code', '9991')->firstOrFail();

        $windows = Credential::firstOrCreate(
            ['name' => 'WinRM Windows Server'],
            ['type' => CredentialType::Winrm, 'username' => 'Administrador'],
        );
        $zabbix = Credential::firstOrCreate(
            ['name' => 'API Zabbix'],
            ['type' => CredentialType::Password, 'username' => 'ansible'],
        );

        $types = [
            ['name' => 'pve', 'ansible_group' => 'pve', 'host_name' => 'pve{{num_taula}}-{{username}}', 'address' => '{{pve_ip}}'],
            ['name' => 'router', 'ansible_group' => 'vm_router', 'host_name' => 'router{{num_taula}}', 'address' => '{{router_ip}}'],
            ['name' => 'dns', 'ansible_group' => 'vm_dns_bind', 'host_name' => 'dns{{num_taula}}', 'address' => '{{dns_ip}}'],
            [
                'name' => 'dnswin', 'ansible_group' => 'vm_dns_windows', 'host_name' => 'dnswin{{num_taula}}', 'address' => '{{dnswin_ip}}',
                'credential_id' => $windows->id,
                'connection_vars' => [
                    'ansible_connection' => 'winrm',
                    'ansible_winrm_transport' => 'ntlm',
                    'ansible_winrm_server_cert_validation' => 'ignore',
                    'ansible_port' => 5985,
                ],
            ],
            [
                'name' => 'zbx', 'ansible_group' => 'zabbix', 'scope' => HostScope::Global, 'host_name' => 'zbx', 'address' => '{{zabbix_server}}',
                'credential_id' => $zabbix->id,
                'connection_vars' => [
                    'ansible_connection' => 'httpapi',
                    'ansible_network_os' => 'community.zabbix.zabbix',
                    'ansible_httpapi_port' => 80,
                    'ansible_httpapi_use_ssl' => false,
                    'ansible_zabbix_url_path' => 'zabbix',
                ],
            ],
        ];
        foreach ($types as $position => $type) {
            $module->hostTypes()->updateOrCreate(['name' => $type['name']], [...$type, 'position' => $position + 1]);
        }

        $module->secrets()->firstOrCreate(
            ['key' => 'jaume_password'],
            ['description' => 'Contrasenya de l\'usuari jaume als equips Linux dels alumnes'],
        );

        $action = $module->actions()->updateOrCreate(
            ['name' => 'Contrasenya de l\'usuari jaume'],
            [
                'description' => 'Posa la contrasenya del secret jaume_password a l\'usuari jaume (que entra amb clau).',
                'executor' => Executor::Ansible,
                'position' => 1,
            ],
        );
        if (! $action->versions()->exists()) {
            $action->versions()->create([
                'version' => 1,
                'code' => <<<'YAML'
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
