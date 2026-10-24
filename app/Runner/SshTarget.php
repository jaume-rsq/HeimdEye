<?php

namespace App\Runner;

/** Un equip on l'executor ssh executa el codi. */
final readonly class SshTarget
{
    public function __construct(
        public string $name,
        public string $host,
        public ?string $user,
        public ?string $key = null,
        public ?int $port = null,
    ) {}
}
