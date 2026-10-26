<?php

namespace App\Runner\Executors;

use App\Enums\CheckStatus;
use App\Runner\CheckResult;
use App\Runner\ExecutionContext;
use App\Runner\ProcessRunner;
use App\Runner\SshOptions;
use App\Runner\SshTarget;

/**
 * Executa el codi amb bash als equips de la capçalera "# hosts:", per SSH, un rere l'altre.
 *
 * Les variables HE_* s'exporten al principi de l'script remot. Si ssh surt amb el codi 255
 * (no connecta, clau rebutjada...), el resultat d'aquell equip és unavailable. El resultat
 * global és pass només si passa a tots els equips; si no, el del primer equip que falla.
 */
class SshExecutor implements Executor
{
    public function __construct(private ProcessRunner $processes) {}

    public function execute(ExecutionContext $context): CheckResult
    {
        if ($context->targets === []) {
            return CheckResult::error('No hi ha cap equip on executar el codi');
        }

        $results = [];
        foreach ($context->targets as $target) {
            $results[$target->name] = $target->user
                ? $this->run($context, $target)
                : CheckResult::error("L'equip {$target->name} no té usuari SSH (cal una credencial)");
        }

        return $this->combine($results);
    }

    private function run(ExecutionContext $context, SshTarget $target): CheckResult
    {
        $command = [
            'ssh',
            '-o', 'BatchMode=yes',
            '-o', 'ConnectTimeout='.min(10, $context->timeout),
            ...SshOptions::HOST_KEYS,
        ];
        if ($target->key) {
            array_push($command, '-i', $target->key);
        }
        if ($target->port) {
            array_push($command, '-p', (string) $target->port);
        }
        array_push($command, "{$target->user}@{$target->host}", 'bash -s');

        return $this->processes->run(
            $command,
            [],
            $this->exports($context->env).$context->code,
            $context->timeout,
            fn (int $exitCode) => $exitCode === 255 ? CheckStatus::Unavailable : CheckStatus::Fail,
        );
    }

    /** @param  array<string, CheckResult>  $results  per nom d'equip */
    private function combine(array $results): CheckResult
    {
        if (count($results) === 1) {
            return reset($results);
        }

        $failed = array_filter($results, fn (CheckResult $result) => ! $result->passed());
        $main = $failed ? reset($failed) : null;
        $output = '';
        foreach ($results as $name => $result) {
            $output .= "== {$name}: {$result->status->value}\n".trim((string) $result->output)."\n";
        }

        return new CheckResult(
            $main?->status ?? CheckStatus::Pass,
            $main
                ? array_key_first($failed).': '.$main->message
                : implode('; ', array_map(fn ($name, CheckResult $r) => "{$name}: {$r->message}", array_keys($results), $results)),
            rtrim($output),
            $main?->exitCode ?? 0,
            array_sum(array_map(fn (CheckResult $r) => $r->durationMs, $results)),
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
