<?php

namespace Tests\Unit;

use App\Enums\Executor;
use App\Runner\CommandPreview;
use App\Runner\ExecutionContext;
use App\Runner\SshTarget;
use PHPUnit\Framework\TestCase;

class CommandPreviewTest extends TestCase
{
    public function test_bash_variables_are_replaced_and_secrets_masked(): void
    {
        $context = new ExecutionContext(
            code: 'dig @"$HE_PARAM_SERVIDOR_DNS" "${HE_PARAM_NOM_DNS}" $HE_DESCONEGUDA # $HE_SECRET_CLAU',
            env: ['HE_PARAM_SERVIDOR_DNS' => '10.0.0.2', 'HE_PARAM_NOM_DNS' => 'ns1.t01.cat', 'HE_SECRET_CLAU' => 'molt-secreta'],
            timeout: 10,
            targets: [new SshTarget('dns01', '10.0.0.2', 'jaume')],
            mask: ['molt-secreta'],
        );

        $this->assertSame(
            "# bash al servidor HeimdEye\ndig @\"10.0.0.2\" \"ns1.t01.cat\" \$HE_DESCONEGUDA # ********",
            CommandPreview::describe(Executor::Local, $context),
        );
        $this->assertStringStartsWith("# ssh jaume@10.0.0.2 (dns01)\ndig @\"10.0.0.2\"", CommandPreview::describe(Executor::Ssh, $context));
    }

    public function test_ansible_shows_the_hosts_and_the_extra_vars(): void
    {
        $context = new ExecutionContext(
            code: "- hosts: pve\n  tasks:\n    - debug: msg=\"{{ DIR }} {{ ip_pve }} {{ CLAU | password_hash('sha512') }}\"",
            env: [],
            timeout: 10,
            hosts: ['pve01-alumne01'],
            extraVars: ['DIR' => '/tmp/x', 'CLAU' => 'molt-secreta'],
            mask: ['molt-secreta'],
            checkMode: true,
        );

        $this->assertSame(
            "# ansible-playbook --check --diff a pve01-alumne01\n# -e DIR=/tmp/x\n# -e CLAU=********\n- hosts: pve\n  tasks:\n    - debug: msg=\"/tmp/x {{ ip_pve }} {{ CLAU | password_hash('sha512') }}\"",
            CommandPreview::describe(Executor::Ansible, $context),
        );
    }
}
