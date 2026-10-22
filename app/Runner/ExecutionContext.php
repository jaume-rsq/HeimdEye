<?php

namespace App\Runner;

/** Tot el que un executor necessita per executar el codi d'un check. */
final readonly class ExecutionContext
{
    /**
     * @param  array<string, string>  $env  variables HE_* de l'alumne
     * @param  array<string, mixed>  $config  config de la versió, amb les plantilles ja resoltes
     */
    public function __construct(
        public string $code,
        public array $env,
        public array $config,
        public int $timeout,
        public ?string $sshUser = null,
        public ?string $sshKey = null,
    ) {}
}
