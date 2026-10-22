<?php

namespace App\Runner;

/** Tot el que un executor necessita per executar el codi d'un check o d'una acció. */
final readonly class ExecutionContext
{
    /**
     * @param  array<string, string>  $env  variables HE_* de l'alumne (i secrets referenciats)
     * @param  array<string, mixed>  $config  config de la versió, amb les plantilles ja resoltes
     * @param  array<string, mixed>|null  $inventory  inventari Ansible, si l'executor el necessita
     * @param  array<string, mixed>  $extraVars  variables extra per a Ansible (paràmetres i secrets)
     * @param  list<string>  $mask  valors que no poden sortir mai a la sortida
     */
    public function __construct(
        public string $code,
        public array $env,
        public array $config,
        public int $timeout,
        public ?string $sshUser = null,
        public ?string $sshKey = null,
        public ?array $inventory = null,
        public array $extraVars = [],
        public array $mask = [],
        public bool $checkMode = false,
        public ?TempFiles $files = null,
    ) {}
}
