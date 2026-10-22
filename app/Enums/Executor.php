<?php

namespace App\Enums;

/**
 * On i com s'executa el codi d'un check.
 */
enum Executor: string
{
    case Local = 'local';
    case Ssh = 'ssh';
    case Ansible = 'ansible';
    case Http = 'http';
}
