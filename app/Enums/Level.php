<?php

namespace App\Enums;

/**
 * Nivell on es defineix una variable, un secret o un tipus d'equip. Un de mòdul tapa un de curs,
 * que tapa un de global. Les variables també poden ser d'un alumne (tapen totes les altres) o
 * d'un tipus d'equip (variables Ansible de l'equip, com un group_vars).
 */
enum Level: string
{
    case Global = 'global';
    case Course = 'curs';
    case Module = 'modul';
    case Student = 'alumne';
    case HostType = 'equip';

    /** Ordre de precedència: el més alt guanya. */
    public function rank(): int
    {
        return match ($this) {
            self::Global => 0,
            self::Course => 1,
            self::Module => 2,
            self::Student, self::HostType => 3,
        };
    }
}
