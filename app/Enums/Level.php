<?php

namespace App\Enums;

/**
 * Nivell on es defineix una variable o un secret. Un de mòdul tapa un de curs, que tapa un de global.
 */
enum Level: string
{
    case Global = 'global';
    case Course = 'curs';
    case Module = 'modul';

    /** Ordre de precedència: el més alt guanya. */
    public function rank(): int
    {
        return match ($this) {
            self::Global => 0,
            self::Course => 1,
            self::Module => 2,
        };
    }
}
