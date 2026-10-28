<?php

namespace App\Runner\Executors;

use App\Runner\CheckResult;
use App\Runner\ExecutionContext;
use App\Runner\ProcessRunner;

/** Executa el codi amb bash al servidor HeimdEye (ping, dig, ssh, curl...). */
class LocalExecutor implements Executor
{
    public function __construct(private ProcessRunner $processes) {}

    public function execute(ExecutionContext $context): CheckResult
    {
        $env = [
            ...$context->env,
            'HE_SSH_USER' => $context->sshUser ?? '',
            'HE_SSH_KEY' => $context->sshKey ?? '',
        ];

        // El codi va per stdin: no cal cap fitxer temporal que l'usuari del sandbox hagi de llegir.
        return $this->processes->run(['bash', '-s'], $env, self::stdinScript($context->code), $context->timeout);
    }

    /**
     * El codi dins d'un bloc { ... } amb l'entrada de /dev/null, per a `bash -s`.
     *
     * Amb `bash -s`, l'script és a stdin i bash el llegeix a mesura que l'executa: una ordre que
     * llegeix stdin (ssh sense -n, cat...) es menjaria la resta de l'script, que acabaria amb 0.
     * Bash llegeix el bloc sencer abans d'executar-lo, i a dins stdin és /dev/null.
     */
    public static function stdinScript(string $code): string
    {
        return "{\n{$code}\n} </dev/null\n";
    }
}
