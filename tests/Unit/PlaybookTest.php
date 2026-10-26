<?php

namespace Tests\Unit;

use App\Runner\Ansible\Playbook;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PlaybookTest extends TestCase
{
    public function test_host_groups_come_from_every_play(): void
    {
        $playbook = Playbook::parse("- hosts: pve:vm_router\n  tasks: []\n- hosts: [vm_dns_bind, '!pve']\n  tasks: []\n");

        $this->assertSame(['pve', 'vm_router', 'vm_dns_bind'], $playbook->hostGroups());
    }

    /** @return array<string, array{string}> */
    public static function forbiddenHosts(): array
    {
        return [
            'all' => ["- hosts: all\n  tasks: []\n"],
            'all dins una llista' => ["- hosts: pve:all\n  tasks: []\n"],
            'comodí' => ["- hosts: 'vm_*'\n  tasks: []\n"],
            'variable' => ["- hosts: \"{{ grup }}\"\n  tasks: []\n"],
            'sense hosts' => ["- tasks: []\n"],
            'import_playbook' => ["- import_playbook: altre.yml\n"],
        ];
    }

    #[DataProvider('forbiddenHosts')]
    public function test_forbidden_host_patterns(string $code): void
    {
        $this->expectException(InvalidArgumentException::class);
        Playbook::parse($code)->hostGroups();
    }

    public function test_invalid_yaml(): void
    {
        $this->expectExceptionMessage('YAML');
        Playbook::parse("- hosts: pve\n  tasks: [\n");
    }

    public function test_hashed_or_local_secrets_do_not_leak(): void
    {
        $playbook = Playbook::parse(<<<'YAML'
            - hosts: pve
              tasks:
                - ansible.builtin.user:
                    name: jaume
                    password: "{{ jaume_password | password_hash('sha512', 'heimdeye') }}"
                - ansible.builtin.uri:
                    url: https://zabbix/api
                    body: "{{ zabbix_token }}"
                  delegate_to: localhost
                - block:
                    - ansible.builtin.debug:
                        msg: "{{ zabbix_token }}"
                  delegate_to: 127.0.0.1
                - ansible.builtin.debug:
                    msg: "jaume_password és el nom del secret, no el valor"
            YAML);

        $this->assertSame([], $playbook->leakedSecrets(['jaume_password', 'zabbix_token']));
    }

    public function test_plain_secrets_on_student_hosts_leak(): void
    {
        $playbook = Playbook::parse(<<<'YAML'
            - hosts: pve
              vars:
                pw: "{{ db_password }}"
              tasks:
                - ansible.builtin.copy:
                    content: "token={{ zabbix_token }}\n"
                    dest: /etc/token
                - block:
                    - ansible.builtin.shell: "echo {{ jaume_password }}"
                - ansible.builtin.debug:
                    msg: "{{ jaume_password2 }}"
            YAML);

        $this->assertSame(['db_password', 'zabbix_token', 'jaume_password'], $playbook->leakedSecrets(['jaume_password', 'zabbix_token', 'db_password']));
    }
}
