<?php

namespace App\Runner;

/**
 * Opcions d'ssh comunes als executors ssh i Ansible (i, com a HE_SSH_OPTS, als scripts locals).
 *
 * Les claus de host no es desen: els alumnes reinstal·len les VM sovint i una clau canviada
 * deixaria l'equip inaccessible fins que algú netegés known_hosts a mà.
 */
final class SshOptions
{
    public const HOST_KEYS = [
        '-o', 'StrictHostKeyChecking=no',
        '-o', 'UserKnownHostsFile=/dev/null',
        '-o', 'LogLevel=ERROR',
    ];
}
