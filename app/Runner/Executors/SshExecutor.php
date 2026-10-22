<?php

namespace App\Runner\Executors;

use App\Enums\CheckStatus;
use App\Runner\CheckResult;
use App\Runner\ExecutionContext;
use App\Runner\ProcessRunner;

/**
 * Executa el codi amb bash en un equip de l'alumne, per SSH.
 *
 * Les variables HE_* s'exporten al principi de l'script remot. Si ssh surt amb
 * el codi 255 (no connecta, clau rebutjada...), el resultat és unavailable.
 */
class SshExecutor implements Executor
{
    public function __construct(private ProcessRunner $processes) {}

    public function execute(ExecutionContext $context): CheckResult
    {
        $host = $context->config['host'] ?? null;
        if (! $host) {
            return CheckResult::error('El check SSH no té host configurat');
        }
        if (! $context->sshUser) {
            return CheckResult::error('El mòdul no té credencial SSH');
        }

        $command = [
            'ssh',
            '-o', 'BatchMode=yes',
            '-o', 'ConnectTimeout='.min(10, $context->timeout),
            '-o', 'StrictHostKeyChecking=accept-new',
        ];
        if ($context->sshKey) {
            array_push($command, '-i', $context->sshKey);
        }
        if (isset($context->config['port'])) {
            array_push($command, '-p', (string) $context->config['port']);
        }
        array_push($command, "{$context->sshUser}@{$host}", 'bash -s');

        return $this->processes->run(
            $command,
            [],
            $this->exports($context->env).$context->code,
            $context->timeout,
            fn (int $exitCode) => $exitCode === 255 ? CheckStatus::Unavailable : CheckStatus::Fail,
        );
    }

    /** @param  array<string, string>  $env */
    private function exports(array $env): string
    {
        $lines = '';
        foreach ($env as $name => $value) {
            $lines .= "export {$name}=".escapeshellarg($value)."\n";
        }

        return $lines;
    }
}
