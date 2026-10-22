<?php

namespace App\Enums;

/**
 * Un equip per alumne (pve, router...) o un de sol per a tot el mòdul (servidor Zabbix).
 */
enum HostScope: string
{
    case Student = 'alumne';
    case Global = 'global';
}
