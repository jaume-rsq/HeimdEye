<?php

namespace App\Enums;

/**
 * Tipus de credencial que fan servir els executors remots.
 */
enum CredentialType: string
{
    case Ssh = 'ssh';
    case Winrm = 'winrm';
    case Password = 'password'; // usuari i contrasenya (API de Zabbix...)
}
