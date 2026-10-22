<?php

namespace App\Enums;

/**
 * Resultat d'una acció per a un alumne, a partir del resum d'Ansible.
 */
enum ActionStatus: string
{
    case Ok = 'ok';
    case Changed = 'changed';
    case Failed = 'failed';
    case Unavailable = 'unavailable';
    case Error = 'error';
    case Timeout = 'timeout';
}
