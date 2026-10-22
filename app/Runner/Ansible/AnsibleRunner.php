<?php

namespace App\Runner\Ansible;

use App\Runner\ProcessRunner;
use App\Runner\TempFiles;

/** Executa un playbook amb un inventari generat i en llegeix el resultat del callback heimdeye. */
class AnsibleRunner
{
    public function __construct(private ProcessRunner $processes) {}

    /**
     * @param  array<string, mixed>  $inventory
     * @param  array<string, mixed>  $extraVars
     */
    public function run(TempFiles $files, string $playbook, array $inventory, array $extraVars, bool $checkMode, int $timeout): AnsibleResult
    {
        $command = [
            (string) config('heimdeye.ansible.playbook_bin'),
            '-i', $files->write('inventory.json', json_encode($inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)),
            '-e', '@'.$files->write('extra-vars.json', json_encode((object) $extraVars, JSON_UNESCAPED_UNICODE)),
        ];
        if ($checkMode) {
            array_push($command, '--check', '--diff');
        }
        $command[] = $files->write('playbook.yml', $playbook);

        $run = $this->processes->execute($command, [
            'ANSIBLE_STDOUT_CALLBACK' => 'heimdeye',
            'ANSIBLE_CALLBACK_PLUGINS' => resource_path('ansible/callback_plugins'),
            'ANSIBLE_NOCOLOR' => '1',
            'ANSIBLE_RETRY_FILES_ENABLED' => '0',
            'ANSIBLE_SSH_ARGS' => '-o StrictHostKeyChecking=accept-new -o ControlMaster=auto -o ControlPersist=60s',
        ], '', $timeout);

        $report = null;
        foreach (array_reverse(preg_split('/\R/', $run['stdout'])) as $line) {
            if (str_starts_with($line, '{"events"')) {
                $report = json_decode($line, true);
                break;
            }
        }

        return new AnsibleResult(
            stats: $report['stats'] ?? [],
            events: $report['events'] ?? [],
            exitCode: $run['exitCode'],
            timedOut: $run['timedOut'],
            error: $run['error'],
            rawOutput: $this->processes->limit($run['stdout']."\n".$run['stderr']) ?? '',
            durationMs: $run['durationMs'],
        );
    }
}
