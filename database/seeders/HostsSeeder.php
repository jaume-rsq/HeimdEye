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

        $actions = [
            [
                'nom' => 'Contrasenya de l\'usuari jaume',
                'descripcio' => 'Posa la contrasenya del secret jaume_password a l\'usuari jaume (que entra amb clau).',
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
            ],
            [
                'nom' => 'Accés de jaume als nodes PVE',
                'descripcio' => 'Sudo sense contrasenya, clau pública i contrasenya de jaume; usuari jaume@pam administrador a PVE i, si hi és, a PBS. Cal que jaume ja pugui fer sudo sense contrasenya.',
                'parametres' => [
                    ['name' => 'jaume_pubkey_file', 'label' => 'Clau pública (fitxer a la VM de HeimdEye)', 'default' => '~/.ssh/id_ed25519.pub'],
                ],
                'codi' => <<<'YAML'
                    - name: Accés de jaume als nodes PVE
                      hosts: pve
                      become: true
                      tasks:
                        - name: Instal·lar sudo
                          ansible.builtin.apt:
                            name: sudo
                            state: present

                        - name: Sudo sense contrasenya
                          ansible.builtin.copy:
                            dest: /etc/sudoers.d/jaume
                            content: "jaume ALL=(ALL) NOPASSWD:ALL\n"
                            owner: root
                            group: root
                            mode: "0440"
                            validate: "visudo -cf %s"

                        - name: Clau pública de jaume
                          ansible.posix.authorized_key:
                            user: jaume
                            key: "{{ lookup('file', jaume_pubkey_file) }}"
                            state: present

                        - name: Usuari jaume amb contrasenya
                          ansible.builtin.user:
                            name: jaume
                            shell: /bin/bash
                            # Sal fixa perquè el hash no canviï a cada execució (idempotent).
                            password: "{{ jaume_password | password_hash('sha512', 'heimdeye') }}"
                            update_password: always

                        - name: Llistar usuaris de PVE
                          ansible.builtin.command: pveum user list --output-format json
                          register: pve_users
                          changed_when: false

                        - name: Crear usuari jaume@pam a PVE
                          ansible.builtin.command: pveum user add jaume@pam
                          when: '"jaume@pam" not in pve_users.stdout'

                        - name: Donar rol Administrator a / a PVE
                          ansible.builtin.command: pveum acl modify / --users jaume@pam --roles Administrator
                          changed_when: false # "modify" és idempotent, però no informa de si ha canviat

                        - name: Comprovar si PBS està instal·lat
                          ansible.builtin.stat:
                            path: /usr/sbin/proxmox-backup-manager
                          register: pbs

                        - name: Llistar usuaris de PBS
                          ansible.builtin.command: proxmox-backup-manager user list --output-format json
                          register: pbs_users
                          changed_when: false
                          when: pbs.stat.exists

                        - name: Crear usuari jaume@pam a PBS
                          ansible.builtin.command: proxmox-backup-manager user create jaume@pam
                          when:
                            - pbs.stat.exists
                            - '"jaume@pam" not in pbs_users.stdout'

                        - name: Donar rol Admin a / a PBS
                          ansible.builtin.command: proxmox-backup-manager acl update / Admin --auth-id jaume@pam
                          changed_when: false
                          when: pbs.stat.exists
                    YAML,
            ],
        ];
        foreach ($actions as $position => $def) {
            $action = $module->actions()->updateOrCreate(
                ['nom' => $def['nom']],
                [
                    'descripcio' => $def['descripcio'],
                    'executor' => Executor::Ansible,
                    'parametres' => $def['parametres'] ?? null,
                    'posicio' => $position + 1,
                ],
            );
            if (! $action->versions()->exists()) {
                $action->versions()->create(['versio' => 1, 'codi' => $def['codi']]);
            }
        }
    }
}
