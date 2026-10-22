<?php

namespace App\Enums;

/**
 * Què ha llançat un lot d'execucions (de checks o d'una acció).
 */
enum RunOrigin: string
{
    case Nightly = 'nightly';
    case Manual = 'manual';
    case Test = 'test';
    case Action = 'action';
}
