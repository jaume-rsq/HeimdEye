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
        return $this->processes->run(['bash', '-s'], $env, $context->code, $context->timeout);
    }
}
