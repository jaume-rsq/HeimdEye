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
    // L'agent que l'alumne té instal·lat a la instància recull la feina i l'executa en local.
    case Agent = 'agent';
}
